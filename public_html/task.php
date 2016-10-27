<?php
/*------------------------------------------------------------------------------
  $Id$

  AbanteCart, Ideal OpenSource Ecommerce Solution
  http://www.AbanteCart.com

  Copyright © 2011-2016 Belavier Commerce LLC

  This source file is subject to Open Software License (OSL 3.0)
  License details is bundled with this package in the file LICENSE.txt.
  It is also available at this URL:
  <http://www.opensource.org/licenses/OSL-3.0>
  
 UPGRADE NOTE: 
   Do not edit or add to this file if you wish to upgrade AbanteCart to newer
   versions in the future. If you wish to customize AbanteCart for your
   needs please refer to http://www.AbanteCart.com for more information.  
------------------------------------------------------------------------------*/

// Required PHP Version
define('MIN_PHP_VERSION', '5.3.0');
if (version_compare(phpversion(), MIN_PHP_VERSION, '<') == true){
	die(MIN_PHP_VERSION . '+ Required for AbanteCart to work properly! Please contact your system administrator or host service provider.');
}

// Load Configuration
// Real path (operating system web root) to the directory where abantecart is installed
$root_path = dirname(__FILE__);

// Windows IIS Compatibility  
if (stristr(PHP_OS, 'WIN')){
	define('IS_WINDOWS', true);
	$root_path = str_replace('\\', '/', $root_path);
}

define('DIR_ROOT', $root_path);
define('DIR_CORE', DIR_ROOT . '/core/');

require_once(DIR_ROOT . '/system/config.php');

//set server name for correct email sending
if (defined('SERVER_NAME') && SERVER_NAME != ''){
	putenv("SERVER_NAME=" . SERVER_NAME);
}

// New Installation
if (!defined('DB_DATABASE')){
	header('Location: install/index.php');
	exit;
}

// sign of admin side for controllers run from dispatcher
$_GET['s'] = ADMIN_PATH;
// Load all initial set up
require_once(DIR_ROOT . '/core/init.php');
// not needed anymore
unset($_GET['s']);

//detect run mode
$command_line = false;
if (php_sapi_name() == "cli"){
	//command line
	echo "Running command line \n";
	$command_line = true;
	$mode = 'start';
	$task_id = $argv[1];
	$step_id = $argv[2];
}else{

	// add to settings API et task_api_key
	$task_api_key = $config->get('task_api_key');
	if(!$task_api_key || $task_api_key != (string)$_GET['task_api_key']){
		exit('Authorize to access.');
	}
	$mode = (string)$_GET['mode'];
	$task_id = (int)$_GET['task_id'];
	$step_id = (int)$_GET['step_id'];
}

if(!$mode && !$command_line){
	exit("Error: Unknown mode!");
}


ADebug::checkpoint('init end');

// Currency
$registry->set('currency', new ACurrency($registry));

//ok... let's start tasks
$tm = new ATaskManager(($command_line ? 'cli' : 'html'));

//if task_id is not presents
if($mode == 'start' && !$task_id){
	//try to remove execution time limitation (can not work on some hosts!)
	ini_set("max_execution_time", "0");
	//start all scheduled tasks one by one
	$tm->runTasks();

}elseif ($mode == 'start' && $task_id && $step_id){
	if($tm->canStepRun($task_id, $step_id)){
		$step_details = $tm->getTaskStep($task_id, $step_id);
		$tm->runStep($step_details);
	}
}elseif ($mode == 'start' && $task_id && !$step_id){

	$tm->updateTask($task_id, array(
			'status' => $tm::STATUS_READY,
			'start_time' => date('Y-m-d H:i:s'))
	);

	$task_details = $tm->getTaskById($task_id);
	foreach($task_details['steps'] as $step){
		$tm->updateStep($step['step_id'], array('status'=> $tm::STATUS_READY));
	}



	//run all steps of task and change it's status after
	$data = array('task_details' => $task_details);
	$tm->runTask($task_id);
	session_write_close();
}

//get log for each task ans steps
$run_log = $command_line ? $tm->run_log : nl2br($tm->run_log);
ob_flush();
echo $run_log;

ADebug::checkpoint('app end');

//display debug info
ADebug::display();

//add html to run task in browser with ajax calls (for task step split run)

if( $command_line !== true && !$step_id) {
?>
<!DOCTYPE html>
<html lang="en_gb" dir="auto" >
<head>
<meta charset="utf-8">
<title>Task Run</title>
<style>
	.loading {
	  font-size: 20px;
	}

	.loading:after {
	  overflow: hidden;
	  display: inline-block;
	  vertical-align: bottom;
	  -webkit-animation: ellipsis steps(4,end) 900ms infinite;
	  animation: ellipsis steps(4,end) 900ms infinite;
	  content: "\2026"; /* ascii code for the ellipsis character */
	  width: 0px;
	}

	@keyframes ellipsis {
	  to {
	    width: 1.25em;
	  }
	}

	@-webkit-keyframes ellipsis {
	  to {
	    width: 1.25em;
	  }
	}
</style>
<script src="https://code.jquery.com/jquery-1.12.4.min.js" integrity="sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ="   crossorigin="anonymous"></script>
<script defer type="text/javascript">
	/*
	 task run via ajax
	 */
	jQuery(document).ready(function() {
		var data = <?php echo json_encode($data); ?>;
		runTaskUI(data);
	});

	var base_url =  '<?php echo HTTPS_SERVER; ?>task.php';
	var abort_task_url =  '<?php echo $abort_task_url; ?>';
	var task_fail = false;
	var task_complete_text = task_fail_text = '';

	var defaultTaskMessages = {
	    task_failed: 'Task Failed',
	    task_success: 'Task was completed',
	    task_abort: 'Task was aborted',
	    complete: 'Complete',
	    step: 'Step',
	    failed: 'failed',
	    success: 'success',
	    processing_step: 'processing_step'
	};


	var runTaskUI = function (data) {
	    if (data.hasOwnProperty("error") && data.error == true) {
	        runTaskShowError('Creation of new task failed! Please check error log for details. \n' + data.error_text);
	    } else {
		    $('body').append('<div class="loading">Running</div>');
	        runTaskStepsUI(data.task_details);
	    }
	}


	function runTaskStepsUI(task_details) {
	    if (task_details.status != '1') {
	        runTaskShowError('Cannot to run steps of task "' + task_details.name + '" because status of task is not "ready". Current status - ' + task_details.status);
	    } else {
	        //then run sequential ajax calls
	        //note: all that calls must be asynchronous to be interruptible!
	        var ajaxes = {};
	        for(var k in task_details.steps){
	            var step = task_details.steps[k];
	            var senddata = {
					mode: 'start',
	                task_api_key: '<?php echo $task_api_key; ?>',
					task_id: task_details.task_id,
					step_id: step.step_id
	            };

	            if(step.hasOwnProperty('eta')){
	                senddata['eta'] = step.eta;
	            }
	            ajaxes[k] = {
	                task_id: task_details.task_id,
	                type:'GET',
	                url: base_url,
	                data: senddata,
	                dataType: 'html',
	            };

	            if (step.hasOwnProperty("settings") && step.settings!=null
	                && step.settings.hasOwnProperty("interrupt_on_step_fault")
	                && step.settings.interrupt_on_step_fault == true) {
	                ajaxes[k]['interrupt_on_step_fault'] = true;	            }
	            else{
	                ajaxes[k]['interrupt_on_step_fault'] = false;
	            }
	        }

	        do_seqAjax(ajaxes, 3);
	    }
	};

	function do_seqAjax(ajaxes, attempts_count){

	       $.xhrPool = [];
	       $.xhrPool.abortAll = function() {
	           $(this).each(function(i, jqXHR) {   //  cycle through list of recorded connection
	               jqXHR.abort();  //  aborts connection
	               $.xhrPool.splice(i, 1); //  removes from list by index
	           });
	       };

	        var current = 0,
	            current_key,
	            keys = [];
	        for(var k in ajaxes){
	            keys.push(k);
	        }
	        var steps_cnt = keys.length;
	        var attempts = attempts_count || 3;// set attempts count for fail ajax call (for repeating request)
	        var kill = false;

	        //declare your function to run AJAX requests
	        var do_ajax = function() {

		        //interrupt recursion when:
                //kill task
                // task complete

                if (kill || current >= steps_cnt) {
	                $('body').append('Run Complete');
	                $('div.loading').remove();
                    return;
                }

	            if (current >= steps_cnt) {
	                return;
	            }
	            current_key = keys[current];
	            //make the AJAX request with the given data from the `ajaxes` array of objects
	            ajaxes[current_key].data['t'] = new Date().getTime();

	            $.ajax({
	                type: ajaxes[current_key].type,
	                url: ajaxes[current_key].url,
	                data: ajaxes[current_key].data,
	                dataType: ajaxes[current_key].dataType,
	                global: false,
	                cache: false,
	                beforeSend: function(jqXHR) {
	                    $.xhrPool.push(jqXHR);
	                },
	                success: function (data, textStatus, xhr) {
		                $('body').append(data);
	                    attempts = 3;
	                    current++;
	                },
	                error: function (xhr, status, error) {
	                    var error_txt='';
	                    try { //when server response is json formatted string
	                        var err = $.parseJSON(xhr.responseText);
	                        if (err.hasOwnProperty("error_text")) {
	                            error_txt = err.error_text;
	                        } else {
	                            if(xhr.status==200){
	                                error_txt = '('+xhr.responseText+')';
	                            }else{
	                                error_txt = 'HTTP-status:' + xhr.status;
	                            }
	                            error_txt = 'Connection error occurred. ' + error_txt;
	                        }
	                    } catch (e) {
	                        if(xhr.status==200){
	                            error_txt = '('+xhr.responseText+')';
	                        }else{
	                            error_txt = 'HTTP-status:' + xhr.status;
	                        }
	                        error_txt = 'Connection error occurred. ' + error_txt;
	                    }

	                    //so.. if all attempts of this step are failed
	                    if (attempts == 0) {
	                        task_complete_text += '<div class="alert-danger">'
	                            + defaultTaskMessages.step + ' '
	                            + (current+1) + ' - '
	                            + defaultTaskMessages.failed
	                            +'. ('+ error_txt +')</div>';
	                        //check interruption of task on step failure
	                        if(ajaxes[current_key].interrupt_on_step_fault){
	                            kill=true;
	                            task_fail = true;
	                            xhr.abort();
	                        }else{
	                            task_fail = true;
	                            attempts = 3;
	                        }
	                        current++;
	                    }else {
	                        attempts--;
	                    }
	                },
	                complete: function(jqXHR, text_status){

	                    //  get index for current connection completed
	                    var i = $.xhrPool.indexOf(jqXHR);
	                    //  removes from list by index
	                    if (i > -1){
	                        $.xhrPool.splice(i, 1);
	                    }
	                    if(text_status!='abort') {
	                        do_ajax();
	                    }
	                }
	            });
	        }

	        //first run
	        do_ajax();
	}


	function runTaskShowError(error_text) {
	    document.write('<div class="alert alert-danger" role="alert">' + error_text + '</div>');
	}

</script>
</head>
<body></body>
</html>
<?php }
exit;
