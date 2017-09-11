<?php

namespace WP_Queue;

use Carbon\Carbon;

class DatabaseQueue extends Queue {

	/**
	 * @var wpdb
	 */
	protected $database;

	/**
	 * @var string
	 */
	protected $jobs_table;

	/**
	 * @var string
	 */
	protected $failures_table;

	/**
	 * DatabaseQueue constructor.
	 *
	 * @param wpdb $wpdb
	 */
	public function __construct( $wpdb ) {
		$this->database       = $wpdb;
		$this->jobs_table     = $this->database->prefix . 'queue_jobs';
		$this->failures_table = $this->database->prefix . 'queue_failures';
	}

	/**
	 * Push a job onto the queue.
	 *
	 * @param Job $job
	 * @param int $delay
	 *
	 * @return bool|int
	 */
	public function push( Job $job, $delay = 0 ) {
		$result = $this->database->insert( $this->jobs_table, array(
			'job'          => serialize( $job ),
			'available_at' => $this->datetime( $delay ),
			'created_at'   => $this->datetime(),
		) );

		if ( ! $result ) {
			return false;
		}

		return $this->database->insert_id;
	}

	/**
	 * Retrieve a job from the queue.
	 *
	 * @return bool|Job
	 */
	public function pop() {
		$this->release_reserved();

		$sql = $this->database->prepare( "
			SELECT * FROM {$this->jobs_table}
			WHERE reserved_at IS NULL
			AND available_at <= %s
			ORDER BY available_at
			LIMIT 1
		", $this->datetime() );

		$raw_job = $this->database->get_row( $sql );

		if ( is_null( $raw_job ) ) {
			return false;
		}

		$job = $this->vitalize_job( $raw_job );

		$this->reserve( $job );

		return $job;
	}

	/**
	 * Delete a job from the queue.
	 *
	 * @param Job $job
	 */
	public function delete( $job ) {
		$where = array(
			'id' => $job->id(),
		);

		$this->database->delete( $this->jobs_table, $where );
	}

	/**
	 * Release a job back onto the queue.
	 *
	 * @param Job $job
	 */
	public function release( $job ) {
		$data = array(
			'job'         => serialize( $job ),
			'attempts'    => $job->attempts() + 1,
			'reserved_at' => null,
		);

		$this->database->update( $this->jobs_table, $data, array(
			'id' => $job->id(),
		) );
	}

	/**
	 * Push a job onto the failure queue.
	 *
	 * @param Job $job
	 */
	public function failure( $job ) {
		$this->database->insert( $this->failures_table, array(
			'job'       => serialize( $job ),
			'error'     => $job->error(),
			'failed_at' => $this->datetime(),
		) );

		$this->delete( $job );
	}

	/**
	 * Reserve a job in the queue.
	 *
	 * @param Job $job
	 */
	protected function reserve( $job ) {
		$data = array(
			'reserved_at' => $this->datetime(),
		);

		$this->database->update( $this->jobs_table, $data, array(
			'id' => $job->id(),
		) );
	}

	/**
	 * Release reserved jobs back onto the queue.
	 */
	protected function release_reserved() {
		$expired = $this->datetime( -300 );

		$sql = $this->database->prepare( "
				UPDATE {$this->jobs_table}
				SET attempts = attempts + 1, reserved_at = NULL
				WHERE reserved_at <= %s", $expired );

		$this->database->query( $sql );
	}

	/**
	 * Vitalize Job with latest data.
	 *
	 * @param mixed $raw_job
	 *
	 * @return Job
	 */
	protected function vitalize_job( $raw_job ) {
		$job = unserialize( $raw_job->job );

		$job->set_id( $raw_job->id );
		$job->set_attempts( $raw_job->attempts );
		$job->set_reserved_at( new Carbon( $raw_job->reserved_at ) );
		$job->set_available_at( new Carbon( $raw_job->available_at ) );
		$job->set_created_at( new Carbon( $raw_job->created_at ) );

		return $job;
	}

	/**
	 * Get total jobs in the queue.
	 *
	 * @return int
	 */
	public function jobs() {
		$sql = "SELECT COUNT(*) FROM {$this->jobs_table}";
		
		return (int) $this->database->get_var( $sql );
	}

	/**
	 * Get total jobs in the failures queue.
	 *
	 * @return int
	 */
	public function failed_jobs() {
		$sql = "SELECT COUNT(*) FROM {$this->failures_table}";

		return (int) $this->database->get_var( $sql );
	}

	/**
	 * Get MySQL datetime.
	 *
	 * @param int $offset Seconds, can pass negative int.
	 *
	 * @return string
	 */
	protected function datetime( $offset = 0 ) {
		$timestamp = time() + $offset;

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

}