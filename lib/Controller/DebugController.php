<?php


namespace OCA\Appointments\Controller;

use OC_Util;
use OCA\Appointments\Backend\BackendManager;
use OCA\Appointments\Backend\BackendUtils;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class DebugController extends Controller
{
	private $userId;
	private $config;
	private $utils;
	/** @var \OCA\Appointments\Backend\IBackendConnector $bc */
	private $bc;

	public function __construct($AppName,
								IRequest $request,
		$UserId,
								IConfig $config,
								BackendUtils $utils,
								BackendManager $backendManager)
	{
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->config = $config;
		$this->utils = $utils;
		/** @noinspection PhpUnhandledExceptionInspection */
		$this->bc = $backendManager->getConnector();
	}

	/**
	 * @NoAdminRequired
	 */
	function index(): DataResponse
	{
		sleep(1);

		return match ($this->request->getParam('a')) {
			'settings_dump' => $this->settingsDump(),
			'get_raw' => $this->getRawCalendarData(),
			'sync' => $this->syncRemoteNow(),
			default => new DataResponse('Not Found', Http::STATUS_NOT_FOUND),
		};
	}

	private function settingsDump(): DataResponse
	{
		$data = '<strong>Nextcloud Version</strong>: ' . OC_Util::getVersionString() . "\n"
			. '<strong>Appointments Version</strong>: ' . $this->config->getAppValue($this->appName, 'installed_version', "N/A") . "\n"
			. '<strong>Time zone</strong>: ' . $this->utils->getUserTimezone($this->userId, $this->config)->getName() . " ("
			. "calendar: " . $this->config->getUserValue($this->userId, 'calendar', 'timezone', "N/A") . ", "
			. "core: " . $this->config->getUserValue($this->userId, 'core', 'timezone', "N/A") . ")\n"
			. '<strong>Embeding Frame Ancestor</strong>: ' . $this->config->getAppValue($this->appName, 'emb_afad_' . $this->userId, "N/A") . "\n"
			. '<strong>Embeding Button URL</strong>: ' . $this->config->getAppValue($this->appName, 'emb_cncf_' . $this->userId, "N/A") . "\n"
			. '<strong>Extension Notify</strong>: ' . $this->config->getAppValue($this->appName, 'ext_notify_' . $this->userId, "N/A") . "\n"
			. '<strong>Key</strong>: ' . ($this->config->getUserValue($this->userId, $this->appName, "cnk") !== "" ? "Yes" : "No") . "\n\n";

		return new DataResponse($data);
	}

	private function getRawCalendarData(): DataResponse
	{
		$data = "";
		$status = Http::STATUS_BAD_REQUEST;
		$pageId = $this->request->getParam("p");
		$calId = $this->request->getParam("cal_id");
		if ($calId !== null && $pageId !== null) {

			if (!$this->utils->loadSettingsForUserAndPage($this->userId, $pageId)) {
				return new DataResponse('cannot load settings', Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$calInfo = [];
			for (
				$i = 0,
				$userCals = $this->bc->getCalendarsForUser($this->userId),
				$l = count($userCals); $i < $l; $i++
			) {
				$userCal = $userCals[$i];
				if ($userCal['id'] === $calId) {
					$calInfo = $userCal;
					$calInfo['isSubscription'] = '0';
					break;
				}
			}
			if (empty($calInfo)) {
				// no cal found, let's see if it is a remote calendar
				$calInfo = $this->getCalInfoForSubscription($calId);
			}

			if (empty($calInfo)) {
				$status = Http::STATUS_NOT_FOUND;
			} else {
				$status = Http::STATUS_OK;
				$data = var_export($calInfo, true) . "\n\n" . var_export($this->bc->getRawCalData($calInfo, $this->userId), true);
			}
		}

		return new DataResponse($data, $status);
	}

	/**
	 * @NoAdminRequired
	 */
	function syncRemoteNow(): DataResponse
	{
		$data = "";
		$status = Http::STATUS_BAD_REQUEST;
		$pageId = $this->request->getParam("p");
		$calId = $this->request->getParam("cal_id");
		if ($calId !== null && $pageId !== null) {

			if (!$this->utils->loadSettingsForUserAndPage($this->userId, $pageId)) {
				return new DataResponse('cannot load settings', Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$calInfo = $this->getCalInfoForSubscription($calId);
			if (empty($calInfo)) {
				$status = Http::STATUS_NOT_FOUND;
			} else {

				$syncInterval = intval($this->utils->getUserSettings(BackendUtils::KEY_CLS, $this->userId)[BackendUtils::CLS_TMM_SUBSCRIPTIONS_SYNC]);

				if ($syncInterval < 60) {
					$data = "Appointments App sync is disabled.\nSee 'Settings > Advanced Settings > Weekly Template Settings > Subscriptions Sync Interval'";
				} else {
					$a = [
						"name" => $calInfo["name"],
						"syncStart" => microtime(true)
					];

					$calInfo['syncRemoteNow_call'] = true;
					$this->bc->getRawCalData($calInfo, $this->userId);

					$a["syncEnd"] = microtime(true);
					$a["syncDuration"] = $a["syncEnd"] - $a["syncStart"];

					$data = var_export($calInfo, true) . "\n\n" . var_export($a, true);
				}
				$status = Http::STATUS_OK;
			}
		}
		return new DataResponse($data, $status);
	}

	private function getCalInfoForSubscription(string $id): array
	{
		$calInfo = [];
		for (
			$i = 0,
			$subscriptions = $this->bc->getSubscriptionsForUser($this->userId),
			$l = count($subscriptions); $i < $l; $i++
		) {
			$sub = $subscriptions[$i];
			if ($sub['id'] === $id) {
				$calInfo = $sub;
				$calInfo['isSubscription'] = '1';
				break;
			}
		}
		return $calInfo;
	}

}