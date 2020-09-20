<?php class Win32service {
	
	// Service status messages
	private $message = [
		WIN32_SERVICE_STOPPED => 'Stopped',
		WIN32_SERVICE_RUNNING => 'Running',
		WIN32_SERVICE_PAUSED => 'Paused',
	];
	
	// Internal timer
	private $timer = 0;
	
	// Configuration
	private $config;
	
	// Service data and status
	private $service;
	private $status;
	
	// Construct with service and configuration
	public function __construct($service, $config) {
		$this->service = $service;
		$this->config = $config;
	}
	
	// Logging
	private function log($text) {
		if(strlen($text) == 0) return; // Output buffer sends an empty string on destruction
		
		// Prepend datetime
		$line = date("Y-m-d H:i:s \t").$text."\r\n";
		
		// Write into log
		$file = $this->config['logpath'].'/'.date('Y-m-d').'.txt';
		file_put_contents($file, $line, FILE_APPEND);
	}
	
	// Set the service status
	private function setStatus($code) {
		win32_set_service_status($code);
		
		// Log status changes
		if($this->status != $code) print "Service \t".$this->message[$code];
		$this->status = $code;
	}
	
	// Handle command message
	private function handleMessage($code) {
		switch($code) {
			
			// Stop the service
			case WIN32_SERVICE_CONTROL_STOP:
			case WIN32_SERVICE_CONTROL_PRESHUTDOWN: {
				$this->setStatus(WIN32_SERVICE_STOPPED);
			} return false;
			
			// Pause the service
			case WIN32_SERVICE_CONTROL_PAUSE: {
				$this->setStatus(WIN32_SERVICE_PAUSED);
			} return true;
			
			// Run the service (any other message than stopping or pausing)
			default: {
				$this->setStatus(WIN32_SERVICE_RUNNING);
				
				// Trigger a cycle if the pause is over
				if($this->timer < $this->config['interval']) try {
					call_user_func($this->config['runner']);
					
				// Exception handling
				} catch(Exception $e) {
					print "EXCEPTION\t".var_export($e->getMessage(), true);
				}
			} return true;
		}
	}
	
	// Install/uninstall service
	public function install($mode=true) {
		$code = $mode ? win32_create_service($this->service) : win32_delete_service($this->service['service']);
		if($code !== WIN32_NO_ERROR) throw new Exception('0x'.dechex($code));
		print 'Service successfully '.($mode ? 'created' : 'deleted').".\r\n";
	}
	
	// Start service
	public function start() {
		
		// Check log directory
		if(!is_dir($this->config['logpath'])) mkdir($this->config['logpath']);
		
		// Drain output to log as soon as buffer size >= 1
		ob_start([$this, 'log'], 1);
		
		// Link to SMC and start looping
		win32_start_service_ctrl_dispatcher($this->service['service']);
		while($this->handleMessage(win32_get_last_control_message())) {
			
			// Wait and increase the timer (mod $pause to avoid big numbers)
			usleep($this->config['interval']);
			$this->timer+= $this->config['interval'];
			$this->timer%= $this->config['pause'] * (10**6);
		}
	}
}