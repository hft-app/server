<?php class Cache {
	private $controller, $refreshed;
	
	// Constructor
	public function __construct($controller) {
		$this->controller = $controller;
		
		// Setup rate limiting
		$this->controller->lsf->requestLimit = 3.0;
		$this->controller->hft->requestLimit = 3.0;
		
		// Reset refresh times
		$this->refreshed = [
			'meals' => 0,
			'events' => 0,
			'subjects' => 0,
			'professors' => time(),
		];
	}
	
	// This method refreshes a single cache and then returns so that control messages can be handled
	public function cycle() {
		
		// Skip maintenance period
		if(date('H') < 2) return sleep(10);
				
		// Clear inactive devices and users
		$devices = $this->controller->db->query('DELETE FROM devices WHERE active < ADDDATE(CURRENT_TIMESTAMP, INTERVAL -3 MONTH)');
		$users = $this->controller->db->query('DELETE FROM users WHERE active < ADDDATE(CURRENT_TIMESTAMP, INTERVAL -1 YEAR)');
		if($devices->rowCount() > 0) print 'cleared '.$devices->rowCount().' devices';
		if($users->rowCount() > 0) print 'cleared '.$users->rowCount().' users';
		
		// Refresh subjects
		if(time() - $this->refreshed['subjects'] > 60*60*24) {
			$this->refreshed['subjects'] = time();
			
			// Log action
			print 'refreshing subjects';
			
			// Fetch subjects
			$subjects = new Collection\Subjects();
			$subjects->fetch($this->controller->lsf);
			$subjects->write($this->controller->db);
			
			// Log action
			return print $subjects->length().' subjects refreshed';
		}
		
		// Refresh events
		if(time() - $this->refreshed['events'] > 60*60*24) {
			$this->refreshed['events'] = time();
			
			// Log action
			print 'refreshing events';
			
			// Fetch events
			$events = new Collection\Events();
			$events->fetch($this->controller->hft);
			$events->write($this->controller->db);
			
			// Log action
		}
			return print $events->length().' events refreshed';
		
		// Refresh meals
		if(time() - $this->refreshed['meals'] > 60*60*24) {
			$this->refreshed['meals'] = time();
			
			// Log action
			print 'refreshing meals';
			
			// Fetch meals
			$meals = new Collection\Meals();
			$meals->fetch($this->controller->sws);
			$meals->write($this->controller->db);
			
			// Log action
			return print $meals->length().' meals refreshed';
		}
		
		// Refresh professors
		if(time() - $this->refreshed['professors'] > 60*60*24*7) {
			$this->refreshed['professors'] = time();
			
			// Log action
			print 'refreshing professors';
			
			// Fetch professors
			$professors = new Collection\Professors();
			$professors->fetch($this->controller->hft);
			$professors->write($this->controller->db);
			
			// Log action
		}
			return print $professors->length().' professors refreshed';
		
		// Refresh courses and lectures by subject
		{
			$query['subject'] = $this->controller->db->query('
				SELECT id, parallelid FROM subjects 
				WHERE refreshed IS NULL OR refreshed < ADDDATE(CURRENT_TIMESTAMP, INTERVAL -1 DAY) 
				ORDER BY refreshed ASC LIMIT 1
			');
			
			// A subject has to be refreshed
			if($query['subject']->rowCount() == 1) {
				$subject = $query['subject']->fetch();
				
				// Log action
				print 'refreshing courses and lectures of subject '.$subject['id'];
				
				// Update refresh time
				$this->controller->db->query('UPDATE subjects SET refreshed = CURRENT_TIMESTAMP WHERE id = ?', $subject['id']);
				
				// Fetch courses
				$courses = new Collection\Courses($subject);
				$courses->fetch($this->controller->lsf);
				$courses->write($this->controller->db);
				
				// Fetch lectures
				$lectures = new Collection\Lectures($subject);
				$lectures->fetch($this->controller->lsf);
				$lectures->write($this->controller->db);
					
				// Log action
				return print $courses->length().' courses with a total of '.$lectures->length().' lectures refreshed for subject '.$subject['id'];
			}
		}
		
		// Refresh users
		{
			// f is an exponential function modelling the refresh interval in minutes against the amount of days since the last activity
			$a = 100; // a [days]
			$f_0 = 15; // f(0) [minutes]
			$f_a = 60*24; // f(a) [minutes]
			
			// Setup query
			$query['user'] = $this->controller->db->query('
				SELECT username, displayname, password FROM users 
				WHERE '.$f_0.'*POW('.($f_a/$f_0).', TIMESTAMPDIFF(DAY, active, NOW())/'.$a.') < TIMESTAMPDIFF(MINUTE, refreshed, NOW()) 
				AND valid IS TRUE AND enabled IS TRUE 
				ORDER BY refreshed ASC LIMIT 1
			');
			
			// A user has to be refreshed
			if($query['user']->rowCount() == 1) {
				$user = $query['user']->fetch();
				
				// Update refresh time
				$this->controller->db->query('UPDATE users SET refreshed = CURRENT_TIMESTAMP WHERE username = ?', $user['username']);
				
				// Log action
				print 'refreshing exams for user '.$user['username'];
				
				// Login at gateway
				if(!$this->controller->lsf->login($user['username'], $user['password'])) {
					print 'invalidated user '.$user['username'];
					return $this->controller->db->query('UPDATE users SET valid = FALSE WHERE username = ?', $user['username']);
				}

				// Read old state
				$old = new Collection\Exams($user['username']);
				$old->read($this->controller->db);
			
				// Write new state
				$new = new Collection\Exams($user['username']);
				$new->fetch($this->controller->lsf);
				$new->write($this->controller->db);
			
				// Logout at gateway
				$this->controller->lsf->logout();
				
				// Determine added exams
				$added = [];
				foreach($new->list() as $test) {
					foreach($old->list() as $compare) {
						if($compare['id'] == $test['id'] && $compare['try'] == $test['try']) continue 2;
					} $added[] = $test;
				}
				
				// Setup notification
				if(count($added) > 0) {
					$name = explode(' ', $user['displayname']);
					$subject = count($added) > 1 ? 'Prüfungsergebnisse - '.$added[0]['title'].' und '.(count($added) - 1).' mehr' : 'Prüfungsergebnis - '.$added[0]['title'];
					$info = count($added) > 1 ? 'Es liegen neue Prüfungsergebnisse für dich vor:' : 'Es liegt ein neues Prüfungsergebnis für dich vor:';
					$list = implode("\r\n", array_map(function($exam){ return ($exam['grade'] > 0 ? $exam['grade']."\t" : $exam['status'])."\t".$exam['title']; }, $added));
					
					// Send notification
					$sent = mail($user['username'].'@hft-stuttgart.de', $subject,
						"Hallo ".$name[0]."!\r\n".
						$info."\r\n\r\n".
						$list."\r\n\r\n".
						"Diese Benachrichtigung wurde automatisch erstellt. Falls du keine weiteren Benachrichtigungen erhalten möchtest, antworte einfach mit \"abbestellen\" auf diese Mail.",
						
						"Return-Path: HFT App <info@hft-app.de>\r\n".
						"Reply-To: HFT App <info@hft-app.de>\r\n".
						"From: HFT App <info@hft-app.de>\r\n".
						"Organization: Luniverse\r\n".
						"Content-Type: text/plain; charset=utf-8\r\n".
						"X-Priority: 3\r\n".
						"X-Mailer: PHP/".phpversion()."\r\n".
						"MIME-Version: 1.0\r\n"
					);
					
					// Log notification
					print 'Notification mail sent to '.$user['username'];
					$this->controller->db->query('INSERT INTO mails (username, time, subject, sent) VALUES (:username, :time, :subject, :sent)', [
						'username' => $user['username'],
						'time' => time(),
						'subject' => $subject,
						'sent' => var_export($sent, true)
					]);
				}
				
				// Log action
				return print $new->length().' exams refreshed for user '.$user['username'];
			}
		}
		
		// Service idle
		return sleep(1);
	}
}