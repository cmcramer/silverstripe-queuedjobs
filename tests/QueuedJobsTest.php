<?php

/**
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class QueuedJobsTest extends SapphireTest
{
	public function testQueueJob() {
		$svc = singleton("QueuedJobService");

		// lets create a new job and add it tio the queue
		$job = new TestQueuedJob();
		$jobId = $svc->queueJob($job);
		$list = $svc->getJobList();

		$this->assertEquals(1, $list->count());

		$myJob = null;
		foreach ($list as $job) {
			if ($job->Implementation == 'TestQueuedJob') {
				$myJob = $job;
				break;
			}
		}
		
		$this->assertNotNull($myJob);
		$this->assertTrue($jobId > 0);
		$this->assertEquals('TestQueuedJob', $myJob->Implementation);
		$this->assertNotNull($myJob->SavedJobData);
	}

	public function testJobRunAs() {
		$svc = singleton("QueuedJobService");
		$list = $svc->getJobList();
		foreach ($list as $job) {
			$job->delete();
		}

		$this->logInWithPermission('DUMMY');

		// lets create a new job and add it tio the queue
		$job = new TestQueuedJob();
		$job->runningAs = "DUMMY";
		$jobId = $svc->queueJob($job);
		$list = $svc->getJobList();

		$myJob = $list->First();

		$this->assertEquals("DUMMY@example.org", $myJob->RunAs()->Email);
	}

	public function testQueueSignature() {
		$svc = singleton("QueuedJobService");

		// lets create a new job and add it tio the queue
		$job = new TestQueuedJob();
		$jobId = $svc->queueJob($job);

		$newJob = new TestQueuedJob();
		$newId = $svc->queueJob($newJob);

		$this->assertEquals($jobId, $newId);

		// now try another, but with different params
		$newJob = new TestQueuedJob();
		$newJob->randomParam = 'stuff';
		$newId = $svc->queueJob($newJob);

		$this->assertNotEquals($jobId, $newId);
	}

	public function testProcessJob() {
		$job = new TestQueuedJob();
		$job->setup();
		$job->process();
		// we should now have some  data
		$data = $job->getJobData();
		$this->assertNotNull($data->messages);
		$this->assertFalse($data->isComplete);

		$jd = $data->jobData;
		$this->assertTrue(isset($jd->times));
		$this->assertEquals(1, count($jd->times));

		// now take the 'saved' data and try restoring the job
	}

	public function testResumeJob() {
		$job = new TestQueuedJob();
		$job->setup();
		$job->process();
		// we should now have some  data
		$data = $job->getJobData();

		// so create a new job and restore it from this data

		$job = new TestQueuedJob();
		$job->setup();

		$job->setJobData($data->totalSteps, $data->currentStep, $data->isComplete, $data->jobData, $data->messages);
		$job->process();

		$data = $job->getJobData();
		$this->assertFalse($data->isComplete);
		$jd = $data->jobData;
		$this->assertTrue(isset($jd->times));
		$this->assertEquals(2, count($jd->times));
	}

	public function testInitialiseJob() {
		// okay, lets test it out on the actual service
		$svc = singleton("TestQJService");
		// lets create a new job and add it to the queue
		$job = new TestQueuedJob();
		$id = $svc->queueJob($job);

		$descriptor = DataObject::get_by_id('QueuedJobDescriptor', $id);
		
		$job = $svc->testInit($descriptor);
		$this->assertInstanceOf('TestQueuedJob', $job, 'Job has been triggered');

		$descriptor = DataObject::get_by_id('QueuedJobDescriptor', $id);

		$this->assertEquals(QueuedJob::STATUS_INIT, $descriptor->JobStatus);
	}

	public function testStartJob() {
		// okay, lets test it out on the actual service
		$svc = singleton("QueuedJobService");
		// lets create a new job and add it to the queue

		$this->logInWithPermission('DUMMYUSER');
		
		$job = new TestQueuedJob();
		$job->testingStartJob = true;
		$id = $svc->queueJob($job);

		$this->logInWithPermission('ADMIN');

		$result = $svc->runJob($id);
		$this->assertTrue($result);

		// we want to make sure that the current user is the runas user of the job
		$descriptor = DataObject::get_by_id('QueuedJobDescriptor', $id);
		$this->assertEquals('Complete', $descriptor->JobStatus);
	}

	public function testImmediateQueuedJob() {
		// okay, lets test it out on the actual service
		$svc = singleton("QueuedJobService");
		// lets create a new job and add it to the queue

		$job = new TestQueuedJob(QueuedJob::IMMEDIATE);
		$job->firstJob = true;
		$id = $svc->queueJob($job);

		$job = new TestQueuedJob(QueuedJob::IMMEDIATE);
		$job->secondJob = true;
		$id = $svc->queueJob($job);

		$jobs = $svc->getJobList(QueuedJob::IMMEDIATE);
		$this->assertEquals(2, $jobs->count());

		// now fake a shutdown
		$svc->onShutdown();

		$jobs = $svc->getJobList(QueuedJob::IMMEDIATE);
		$this->assertInstanceOf('DataList', $jobs);
		$this->assertEquals(0, $jobs->count());
	}

	public function testNextJob() {
		$svc = singleton("TestQJService");
		$list = $svc->getJobList();

		foreach ($list as $job) {
			$job->delete();
		}

		$list = $svc->getJobList();
		$this->assertEquals(0, $list->count());

		$job = new TestQueuedJob();
		$id1 = $svc->queueJob($job);

		$job = new TestQueuedJob();
		// to get around the signature checks
		$job->randomParam = 'me';
		$id2 = $svc->queueJob($job);

		$job = new TestQueuedJob();
		// to get around the signature checks
		$job->randomParam = 'mo';
		$id3 = $svc->queueJob($job);

		$this->assertEquals(2, $id3 - $id1);

		$list = $svc->getJobList();
		$this->assertEquals(3, $list->count());

		// okay, lets get the first one and initialise it, then make sure that a subsequent init attempt fails
		$job = $svc->getNextPendingJob();
		
		$this->assertEquals($id1, $job->ID);
		$svc->testInit($job);

		// now try and get another, it should be === false
		$next = $svc->getNextPendingJob();
		
		$this->assertFalse($next);
	}
	
	public function testJobHealthCheck() {
		$svc = singleton("QueuedJobService");
		// lets create a new job and add it to the queue

		$job = new TestQueuedJob(QueuedJob::IMMEDIATE);
		$job->firstJob = true;
		$id = $svc->queueJob($job);
		
		$descriptor = QueuedJobDescriptor::get()->byID($id);
		
		$descriptor->JobStatus = 'Running';
		$descriptor->StepsProcessed = 1;
//		$descriptor->LastProcessedCount = 1;

		$descriptor->write();
		
		$svc->checkJobHealth();
		
		$descriptor = QueuedJobDescriptor::get()->byID($id);
		$this->assertEquals(1, $descriptor->LastProcessedCount);
		
		$svc->checkJobHealth();
		
		$descriptor = QueuedJobDescriptor::get()->byID($id);
		$this->assertEquals(QueuedJob::STATUS_WAIT, $descriptor->JobStatus);
		
		// the same for init broken jobs
		$descriptor->JobTitle = 'Test job broken in init';
		$descriptor->JobStatus = QueuedJob::STATUS_INIT;
		$descriptor->LastProcessedCount = 0;
		$descriptor->write();
		
		$svc->checkJobHealth();
		
		$descriptor = QueuedJobDescriptor::get()->byID($id);
		$this->assertEquals(1, $descriptor->LastProcessedCount);
		
		$svc->checkJobHealth();
		
		$descriptor = QueuedJobDescriptor::get()->byID($id);
		$this->assertEquals(QueuedJob::STATUS_WAIT, $descriptor->JobStatus);
		
	}
}

// stub class to be able to call init from an external context
class TestQJService extends QueuedJobService {
	public function testInit($descriptor) {
		return $this->initialiseJob($descriptor);
	}
}

class TestQueuedJob extends AbstractQueuedJob implements QueuedJob {
	private $type = QueuedJob::QUEUED;

	public function __construct($type=null) {
		if ($type) {
			$this->type = $type;
		}
		$this->times = array();
	}

	public function getJobType() {
		return $this->type;
	}

	public function getTitle() {
		return "A Test job";
	}

	public function setup() {
		$this->totalSteps = 5;
	}

	public function process() {
		$times = $this->times;
		// needed due to quirks with __set
		$times[] = date('Y-m-d H:i:s');
		$this->times = $times;

		$this->addMessage("Updated time to " . date('Y-m-d H:i:s'));
		sleep(1);

		// make sure we're incrementing
		$this->currentStep++;

		// and checking whether we're complete
		if ($this->currentStep == 5) {
			$this->isComplete = true;
		}
	}
}
