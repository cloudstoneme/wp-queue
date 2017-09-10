<?php

namespace WP_Queue;

use Carbon\Carbon;

class DatabaseQueue extends Queue {

	protected $database;

	/**
	 * @var string
	 */
	protected $table;

	/**
	 * DatabaseQueue constructor.
	 *
	 * @param        $wpdb
	 * @param string $table
	 */
	public function __construct( $wpdb, $table = 'jobs' ) {
		$this->database = $wpdb;
		$this->table    = $this->database->prefix . $table;
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
		$result = $this->database->insert( $this->table, array(
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
			SELECT * FROM {$this->table}
			WHERE reserved_at IS NULL
			AND available_at <= %s
			ORDER BY available_at
		", $this->datetime() );

		$raw_job = $this->database->get_row( $sql );

		if ( is_null( $raw_job ) ) {
			return false;
		}

		$this->reserve( $raw_job->id );

		return $this->vitalize_job( $raw_job );
	}

	/**
	 * Reserve a job in the queue.
	 *
	 * @param int $id
	 */
	protected function reserve( $id ) {
		$data = array(
			'reserved_at' => $this->datetime(),
		);

		$this->database->update( $this->table, $data, array(
			'id' => $id,
		) );
	}

	/**
	 * Release reserved jobs back onto the queue.
	 */
	protected function release_reserved() {
		$expired = $this->datetime( -300 );

		$sql = $this->database->prepare( "
				UPDATE {$this->table}
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

}