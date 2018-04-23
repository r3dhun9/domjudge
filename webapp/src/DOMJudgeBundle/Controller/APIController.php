<?php
namespace DOMJudgeBundle\Controller;

use DOMJudgeBundle\Entity\Language;
use DOMJudgeBundle\Entity\Problem;
use FOS\RestBundle\Controller\FOSRestController;

use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Controller\Annotations\Route;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Put;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\Patch;
use FOS\RestBundle\Controller\Annotations\Head;
use FOS\RestBundle\Controller\Annotations\Delete;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

use DOMJudgeBundle\Entity\Contest;
use DOMJudgeBundle\Entity\Event;
use DOMJudgeBundle\Utils\Utils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @Route("/api", defaults={ "_format" = "json" })
 */
class APIController extends FOSRestController {

	public $apiVersion = 4;

	/**
	 * @Get("/")
	 */
	public function getCurrentActiveContestAction() {
		$contests = $this->getContestsAction();
		if (count($contests) == 0) {
			return null;
		} else {
			return $contests[0];
		}
	}

	/**
	 * @Patch("/")
	 * @Security("has_role('ROLE_ADMIN')")
	 */
	public function changeStartTimeAction(Request $request) {
		$contest = $this->getCurrentActiveContestAction();
		if ($contest === NULL) {
			return NULL;
		}
		$args = $request->request->all();
		$response = NULL;
		if ( !isset($args['id']) ) {
			$response = new Response('Missing "id" in request.', 400);
		} else if ( !isset($args['start_time']) ) {
			$response = new Response('Missing "start_time" in request.', 400);
		} else if ( $args['id'] != $contest['id'] ) {
			$response = new Response('Invalid "id" in request.', 400);
		} else {
			$em = $this->getDoctrine()->getManager();
			$contestObject = $em->getRepository(Contest::class)->findOneBy(
				array(
					'cid' => $args['id'],
				)
			);
			$date = date_create($args['start_time']);
			if ( $date === FALSE) {
				$response = new Response('Invalid "start_time" in request.', 400);
			} else {
				$new_start_time = $date->getTimestamp();
				$now = Utils::now();
				if ( $new_start_time < $now + 30 ) {
					$response = new Response('New start_time not far enough in the future.', 403);
				} else if ( FALSE && $contestObject->getStarttime() != NULL && $contestObject->getStarttime() < $now + 30 ) {
					$response = new Response('Current contest already started or about to start.', 403);
				} else {
					$em->persist($contestObject);
					$newStartTimeString = date('Y-m-d H:i:s e', $new_start_time);
					$contestObject->setStarttime($new_start_time);
					$contestObject->setStarttimeString($newStartTimeString);
					$response = new Response('Contest start time changed to ' . $newStartTimeString, 200);
					$em->flush();
				}
			}
		}
		return $response;
	}

	/**
	 * @Get("/version")
	 */
	public function getVersionAction() {
		$data = ['api_version' => $this->apiVersion];
		return $data;
	}

	/**
	 * @Get("/info")
	 */
	public function getInfoAction() {
		$data = [
			'api_version' => $this->apiVersion,
			'domjudge_version' => $this->getParameter('domjudge.version'),
		];
		return $data;
	}

	/**
	 * @Get("/contests")
	 */
	public function getContestsAction(Request $request) {
		$strict = false;
		if ($request->query->has('strict')) {
			$strict = $request->query->getBoolean('strict');
		}
		$em = $this->getDoctrine()->getManager();
		$data = $em->getRepository(Contest::class)->findBy(
			array(
				'enabled' => TRUE,
				'public' => TRUE,
			)
		);
		$penalty_time = $this->get('domjudge.domjudge')->dbconfig_get('penalty_time', 20);
		$use_ext_ids = $this->getParameter('domjudge.useexternalids');

		$result = [];
		foreach ($data as $contest) {
			if ($contest->isActive()) {
				$result[] = $contest->serializeForAPI($penalty_time, $use_ext_ids, $strict);
			}
		}

		return $result;
	}

	/**
	 * @Get("/contests/{id}")
	 */
	public function getSingleContestAction(Request $request, Contest $contest) {
		if ($contest->isActive()) {
			$strict = false;
			if ($request->query->has('strict')) {
				$strict = $request->query->getBoolean('strict');
			}
			$isJury = $this->isGranted('ROLE_JURY');
			if (($isJury && $contest->getEnabled())
				|| (!$isJury && $contest->isActive())) {
				$penalty_time = $this->get('domjudge.domjudge')->dbconfig_get('penalty_time', 20);
				$use_ext_ids = $this->getParameter('domjudge.useexternalids');
				return $contest->serializeForAPI($penalty_time, $use_ext_ids, $strict);
			} else {
				return NULL;
			}
		} else {
			throw new NotFoundHttpException(sprintf('Contest %s not found', $this->getParameter('domjudge.useexternalids') ? $contest->getExternalid() : $contest->getCid()));
		}
	}

	/**
	 * @Get("/contests/{id}/state")
	 */
	public function getContestState(Contest $contest) {
		if ($contest->isActive()) {
			$time_or_null = function($time, $extra_cond = true) {
				if ( !$extra_cond || $time===null || time()<$time ) return null;
				return Utils::absTime($time);
			};
			$result = [];
			$result['started']   = $time_or_null($contest->getStarttime());
			$result['ended']     = $time_or_null($contest->getEndtime(), $result['started']!==null);
			$result['frozen']    = $time_or_null($contest->getFreezetime(), $result['started']!==null);
			$result['thawed']    = $time_or_null($contest->getUnfreezetime(), $result['frozen']!==null);
			// TODO: do not set this for public access (first needs public role)
			// TODO: use real finalized time when we have it (e.g. in ICPC-live branch)
			$result['finalized'] = $time_or_null($contest->getUnfreezetime(), ($result['ended']!==null && $result['thawed']!==null));

			return $result;
		} else {
			throw new NotFoundHttpException(sprintf('Contest %s not found', $this->getParameter('domjudge.useexternalids') ? $contest->getExternalid() : $contest->getCid()));
		}
	}

	/**
	 * @Get("/contests/{cid}/judgement-types")
	 */
	public function getJudgementTypes() {
		$etcDir = realpath($this->getParameter('kernel.root_dir') . '/../../etc/');
		$VERDICTS = [];
		require_once($etcDir . '/common-config.php');
		$result = [];

		foreach ($VERDICTS as $name => $label) {
			$penalty = true;
			$solved = false;
			if ($name == 'correct') {
				$penalty = false;
				$solved = true;
			}
			if ($name == 'compiler-error') {
				$penalty = (bool)$this->get('domjudge.domjudge')->dbconfig_get('compile_penalty', false);
			}
			$result[] = [
				'id' => $label,
				'name' => str_replace('-', ' ', $name),
				'penalty' => $penalty,
				'solved' => $solved,
			];
		}

		return $result;
	}

	/**
	 * @Get("/contests/{cid}/judgement-types/{id}")
	 */
	public function getJudgementType($id) {
		$judgementTypes = $this->getJudgementTypes();
		foreach ($judgementTypes as $judgementType) {
			if ($judgementType['id'] === $id) {
				return $judgementType;
			}
		}

		throw new NotFoundHttpException(sprintf('Judgement type %s not found', $id));
	}

	/**
	 * @Get("/contests/{cid}/languages")
	 */
	public function getLanguages(Request $request) {
		$languages = $this->getDoctrine()->getRepository(Language::class)->findBy(['allow_submit' => true]);

		return array_map(function(Language $language) use ($request) {
			return $language->serializeForAPI($this->getParameter('domjudge.useexternalids'), $request->query->getBoolean('strict', true));
		}, $languages);
	}

	/**
	 * @Get("/contests/{cid}/languages/{id}")
	 */
	public function getLanguage(Request $request, $id) {
		if ($language = $this->getDoctrine()->getRepository(Language::class)->findOneBy(['langid' => $id, 'allow_submit' => true])) {
			return $language->serializeForAPI($this->getParameter('domjudge.useexternalids'), $request->query->getBoolean('strict', true));
		} else {
			throw new NotFoundHttpException(sprintf('Language %s not found', $id));
		}
	}

	/**
	 * @Get("/contests/{cid}/problems")
	 */
	public function getProblems(Request $request, Contest $contest) {
		// TODO: add security check for public/admin. I can't seem to get checkrole() working
		$problems = $this->getDoctrine()->getRepository(Problem::class)->findAllForContest($contest);

		$ordinal = 0;
		return array_map(function(Problem $problem) use (&$ordinal, $request) {
			return $problem->serializeForAPI($this->getParameter('domjudge.useexternalids'), $request->query->getBoolean('strict', true)) + ['ordinal' => $ordinal++];
		}, $problems);
	}

	/**
	 * @Get("/contests/{cid}/problems/{id}")
	 */
	public function getProblem(Request $request, Contest $contest, $id) {
		// TODO: add security check for public/admin. I can't seem to get checkrole() working
		$problems = $this->getDoctrine()->getRepository(Problem::class)->findAllForContest($contest);

		/**
		 * @var Problem $problem
		 */
		foreach ($problems as $idx => $problem) {
			if (($this->getParameter('domjudge.useexternalids') && $problem->getExternalid() == $id) || (!$this->getParameter('domjudge.useexternalids')) && $problem->getProbid() == $id) {
				return $problem->serializeForAPI($this->getParameter('domjudge.useexternalids'), $request->query->getBoolean('strict', true)) + ['ordinal' => $idx];
			}
		}

		throw new NotFoundHttpException(sprintf('Problem %s not found', $id));
	}

	/**
	 * @Get("/contests/{cid}/event-feed")
	 */
	public function getEventFeed(Request $request, Contest $contest) {
		// Make sure this script doesn't hit the PHP maximum execution timeout.
		set_time_limit(0);
		$em = $this->getDoctrine()->getManager();
		if ($request->query->has('since_id')) {
			$since_id = $request->query->getInt('since_id');
			$event = $em->getRepository(Event::class)->findOneBy(
				array(
					'eventid' => $since_id,
					'cid'     => $contest->getCid(),
				)
			);
			if ( $event===NULL ) {
				return new Response('Invalid parameter "since_id" requested.', 400);
			}
		} else {
			$since_id = -1;
		}
		$response = new StreamedResponse();
		$response->headers->set('X-Accel-Buffering', 'no');
		$response->setCallback(function () use ($em, $contest, $request, $since_id) {
			$lastUpdate = 0;
			$lastIdSent = $since_id;
			$typeFilter = false;
			if ($request->query->has('types')) {
				$typeFilter = explode(',', $request->query->get('types'));
			}
			$strict = false;
			if ($request->query->has('strict')) {
				$strict = $request->query->getBoolean('strict');
			}
			$stream = true;
			if ($request->query->has('stream')) {
				$stream = $request->query->getBoolean('stream');
			}
			$isJury = $this->isGranted('ROLE_JURY');
			while (TRUE) {
				$qb = $em->createQueryBuilder()
					->from('DOMJudgeBundle:Event', 'e')
					->select('e.eventid,e.eventtime,e.endpointtype,e.endpointid,e.datatype,e.dataid,e.action,e.content')
					->where('e.eventid > :lastIdSent')
					->setParameter('lastIdSent', $lastIdSent)
					->andWhere('e.cid = :cid')
					->setParameter('cid', $contest->getCid())
					->orderBy('e.eventid', 'ASC');

				if ($typeFilter !== false) {
					$qb = $qb
						->andWhere('e.endpointtype IN (:types)')
						->setParameter(':types', $typeFilter);
				}
				if ( !$isJury ) {
					$restricted_types = ['judgements', 'runs', 'clarifications'];
					if ( $contest->getStarttime() === NULL ||
					     time() < $contest->getStarttime() ) {
						$restricted_types[] = 'problems';
					}
					$qb = $qb
						->andWhere('e.endpointtype NOT IN (:restricted_types)')
						->setParameter(':restricted_types',$restricted_types);
				}

				$q = $qb->getQuery();

				$events = $q->getResult();
				foreach ($events as $event) {
					// FIXME: use the dj_* wrapper as in lib/lib.wrapper.php.
					$data = json_decode(stream_get_contents($event['content']), TRUE);
					// Filter fields with specific access restrictions.
					if ( !$isJury ) {
						if ( $event['endpointtype'] == 'submissions' ) {
							unset($data['entry_point']);
						}
						if ( $event['endpointtype'] == 'problems' ) {
							unset($data['test_data_count']);
						}
					}
					$result = array(
						'id'        => (string)$event['eventid'],
						'type'      => (string)$event['endpointtype'],
						'op'        => (string)$event['action'],
						'data'      => $data,
					);
					if ( !$strict ) $result['time'] = Utils::absTime($event['eventtime']);
					echo json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) . "\n";
					ob_flush();
					flush();
					$lastUpdate = time();
					$lastIdSent = $event['eventid'];
				}

				if ( count($events) == 0 ) {
					if ( !$stream ) break;
					// No new events, check if it's time for a keep alive.
					$now = time();
					if ( $lastUpdate + 60 < $now ) {
						# Send keep alive every 60s. Guarantee according to spec is 120s.
						echo "\n";
						ob_flush();
						flush();
						$lastUpdate = $now;
					}
					# Sleep for little while before checking for new events.
					usleep(50000);
				}
			}
		});
		return $response;
	}
}
