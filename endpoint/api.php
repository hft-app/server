<?php require '../code/controller.php';
try {
	
	// Constructor
	$controller = new Controller();
	$response = ['status' => 'OK'];
	
	// Check and log access
	if(!$controller->guard->pass()) throw new AccessLimit();
	
	// Select device
	$query['device'] = $controller->db->query('
		SELECT devices.id, users.username, users.password, users.enabled, users.valid FROM devices 
		INNER JOIN users ON (devices.user = users.username) 
		WHERE devices.id = ?
	', $controller::get('device'));
	
	// Check device
	if($query['device']->rowCount() != 1) throw new InvalidDevice();
	else $device = $query['device']->fetch();
	
	// Check status
	if(!$device['enabled']) throw new DisabledUser();
	if(!$device['valid']) throw new InvalidCredentials();
	
	// Log activity
	$controller->db->query('UPDATE devices SET active = CURRENT_TIMESTAMP WHERE id = ?', $device['id']);
	$controller->db->query('UPDATE users SET active = CURRENT_TIMESTAMP WHERE username = ?', $device['username']);
			
	// Switch action
	switch($controller::get('action')) {
				
		// Logout request
		case 'logout': {
			$controller->db->query('DELETE FROM devices WHERE id = ?', $controller::get('device'));
		} break;
		
		// Course enrollment
		case 'enroll': {
			$controller->db->query('DELETE FROM enrollments WHERE user = ?', $device['username']);
			if(isset($_REQUEST['courses'])) foreach($_REQUEST['courses'] as $subject => $courses) {
				foreach($courses as $course => $state) {
					if($state) $controller->db->query('INSERT INTO enrollments (user, subject, course) VALUES (?, ?, ?)', [$device['username'], $subject, $course]);
				}
			}
		} break;
		
		// Refresh data
		case 'refresh': {
			
			// Add printers
			$response['printers'] = [];
			$query['printers'] = $controller->db->query('SELECT * FROM printers');
			while($printer = $query['printers']->fetch()) $response['printers'][] = $printer;
			
			// Add subjects
			$response['subjects'] = [];
			$query['subjects'] = $controller->db->query('SELECT id, parallelid, name FROM subjects ORDER BY name ASC');
			while($subject = $query['subjects']->fetch()) $response['subjects'][] = $subject;
			
			// Add lectures
			$response['lectures'] = [];
			$query['lectures'] = $controller->db->query('
				SELECT lectures.start, lectures.end, lectures.room, lectures.professor, courses.title FROM lectures 
				INNER JOIN enrollments ON (enrollments.subject = lectures.subject AND enrollments.course = lectures.course) 
				INNER JOIN courses ON (courses.id = lectures.course AND courses.subject = lectures.subject) 
				WHERE enrollments.user = ? ORDER BY start
			', $device['username']);
			while($lecture = $query['lectures']->fetch()) {
				$lecture['start'] = (new Datetime($lecture['start']))->format('c');
				$lecture['end'] = (new Datetime($lecture['end']))->format('c');
				$response['lectures'][] = $lecture;
			}
			
			// Add exams
			$response['exams'] = [];
			$query['exams'] = $controller->db->query('SELECT title, semester, status, grade, cp, try, date FROM exams WHERE user = ?', $device['username']);
			while($exam = $query['exams']->fetch()) {
				if(!is_null($exam['grade'])) $exam['grade'] = str_replace('.', ',', $exam['grade']);
				if(!is_null($exam['date'])) $exam['date'] = (new DateTime($exam['date']))->format('d.m.Y');
				$response['exams'][] = $exam;
			}
			
			// Sort exams
			usort($response['exams'], function($b, $a){
				$year = substr($a['semester'], 5, 2) <=> substr($b['semester'], 5, 2);
				$semester = strcmp(substr($a['semester'], 0, 4), substr($b['semester'], 0, 4));
				if(!is_null($a['date']) && !is_null($b['date'])) return new DateTime($a['date']) <=> new DateTime($b['date']);
				return $year ?: $semester;
			});
			
			// Add courses
			foreach($response['subjects'] as &$subject) {
				$subject['courses'] = [];
				
				// Query courses
				$query['courses'] = $controller->db->query('
					SELECT id, title, EXISTS(
						SELECT * FROM enrollments 
						WHERE enrollments.course = courses.id 
						AND enrollments.subject = courses.subject 
						AND enrollments.user = :username
					) AS enrolled FROM courses 
					WHERE subject = :subject 
					ORDER BY title ASC
				', [
					'username' => $device['username'],
					'subject' => $subject['id']
				]);
				
				// Fetch result
				while($course = $query['courses']->fetch()) {
					$course['enrolled'] = !!$course['enrolled'];
					$subject['courses'][] = $course;
				}
			}
			
			// Add professors
			$response['professors'] = [];
			$query['professors'] = $controller->db->query('SELECT * FROM professors');
			while($professor = $query['professors']->fetch()) $response['professors'][] = $professor;
			
			// Add events
			$response['events'] = [];
			$query['events'] = $controller->db->query('SELECT * FROM events WHERE start >= CURRENT_DATE OR end >= CURRENT_DATE');
			while($event = $query['events']->fetch()) {
				$event['start'] = (new Datetime($event['start']))->format('c');
				if(!is_null($event['end'])) $event['end'] = (new Datetime($event['end']))->format('c');
				$event['title'] = json_decode($event['title'], true);
				$event['description'] = json_decode($event['description'], true);
				$response['events'][] = $event;
			}
			
			// Add meals
			$response['meals'] = [];
			$query['meals'] = $controller->db->query('SELECT * FROM meals WHERE date >= CURRENT_DATE');
			while($meal = $query['meals']->fetch()) {
				$meal['price'] = str_replace('.', ',', $meal['price']).' €';
				$response['meals'][] = $meal;
			}
			
			// Add tips
			$response['tips'] = [];
			$query['tips'] = $controller->db->query('SELECT * FROM tips ORDER BY sort ASC');
			while($tip = $query['tips']->fetch()) $response['tips'][] = $tip;
		} break;
		
		// Invalid type
		default: throw new InvalidAction();
	}
	
// Exception handling
} catch(Exception $e) {
	$response = [
		'status' => 'error',
		'error' => get_class($e),
	];
	
// Output response
} finally {
	header('Content-Type: application/json');
	print json_encode($response);
}
