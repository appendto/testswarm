<?php
/**
 * "Getrun" action.
 *
 * @author John Resig, 2008-2011
 * @since 0.1.0
 * @package TestSwarm
 */

class GetrunAction extends Action {

	/**
	 * @actionMethod POST: Required.
	 * @actionParam run_token string
	 * @actionParam client_id int
	 */
	public function doAction() {
		$browserInfo = $this->getContext()->getBrowserInfo();
		$conf = $this->getContext()->getConf();
		$db = $this->getContext()->getDB();
		$request = $this->getContext()->getRequest();

		if ( !$request->wasPosted() ) {
			$this->setError( "requires-post" );
			return;
		}

		$runToken = $request->getVal( "run_token" );
		if ( $conf->client->requireRunToken && !$runToken ) {
			$this->setError( "invalid-input", "This TestSwarm does not allow unauthorized clients to join the swarm." );
			return;
		}

		$clientID = $request->getInt( "client_id" );

		if ( !$clientID ) {
			$this->setError( "invalid-input" );
			return;
		}

		// Create a Client object that verifies client id, user agent and run token.
		// Also updates the client 'alive' timestamp.
		// Throws exception (caught higher up) if stuff is invalid.
		$client = Client::newFromContext( $this->getContext(), $runToken, $clientID );

		// Get oldest run for this user agent, that isn't on the max yet and isn't
		// already ran by another client.
		$runID = $db->getOne(str_queryf(
			"SELECT
				run_id
			FROM
				run_useragent
			WHERE useragent_id = %s
			AND   runs < max
			AND NOT EXISTS (SELECT 1 FROM run_client WHERE run_useragent.run_id = run_id AND client_id = %u)
			ORDER BY run_id DESC
			LIMIT 1;",
			$browserInfo->getSwarmUaID(),
			$clientID
		));

		$runInfo = false;

		// A run was found for the current user_agent
		if ( $runID ) {

			$row = $db->getRow(str_queryf(
				"SELECT
					runs.url as run_url,
					jobs.name as job_name,
					runs.name as run_name
				FROM
					runs, jobs
				WHERE runs.id = %u
				AND   jobs.id = runs.job_id
				LIMIT 1;",
				$runID
			));

			if ( $row->run_url && $row->job_name && $row->run_name ) {
				# Mark the run as "in progress" on the useragent
				$db->query(str_queryf(
					"UPDATE run_useragent
					SET
						runs = runs + 1,
						status = 1,
						updated = %s
					WHERE run_id = %u
					AND   useragent_id = %s
					LIMIT 1;",
					swarmdb_dateformat( SWARM_NOW ),
					$runID,
					$browserInfo->getSwarmUaID()
				));

				# Initialize the client run
				$db->query(str_queryf(
					"INSERT INTO run_client
					(run_id, client_id, status, updated, created)
					VALUES(%u, %u, 1, %s, %s);",
					$runID,
					$clientID,
					swarmdb_dateformat( SWARM_NOW ),
					swarmdb_dateformat( SWARM_NOW )
				));

				$runInfo = array(
					"id" => $runID,
					"url" => $row->run_url,
					"desc" => $row->job_name . ' ' . $row->run_name,
				);
			}
		}

		$this->setData( array(
			"confUpdate" => array( "client" => $conf->client ),
			"runInfo" => $runInfo,
		) );
	}
}

