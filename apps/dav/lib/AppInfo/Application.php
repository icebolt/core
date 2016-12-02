<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\AppInfo;

use OCA\DAV\CalDAV\BirthdayService;
use OCA\DAV\Capabilities;
use OCA\DAV\CardDAV\ContactsManager;
use OCA\DAV\CardDAV\SyncService;
use OCA\DAV\HookManager;
use \OCP\AppFramework\App;
use OCP\Contacts\IManager;
use OCP\IUser;
use Symfony\Component\EventDispatcher\GenericEvent;

class Application extends App {

	/**
	 * Application constructor.
	 *
	 * @param array $urlParams
	 */
	public function __construct (array $urlParams= []) {
		parent::__construct('dav', $urlParams);

		/*
		 * Register capabilities
		 */
		$this->getContainer()->registerCapability(Capabilities::class);
	}

	/**
	 * Returns an initialized Sabre server instance
	 *
	 * @return \OCA\DAV\Server server instance
	 */
	public function createSabreServer($baseuri) {
		$request = $this->getContainer()->getServer()->getRequest();
		$server = new \OCA\DAV\Server($request, $baseuri);

		$this->registerSabrePluginsFromApps($server);

		return $server;
	}

	/**
	 * @param IManager $contactsManager
	 * @param string $userID
	 */
	public function setupContactsProvider(IManager $contactsManager, $userID) {
		/** @var ContactsManager $cm */
		$cm = $this->getContainer()->query(ContactsManager::class);
		$urlGenerator = $this->getContainer()->getServer()->getURLGenerator();
		$cm->setupContactsProvider($contactsManager, $userID, $urlGenerator);
	}

	public function registerHooks() {
		/** @var HookManager $hm */
		$hm = $this->getContainer()->query(HookManager::class);
		$hm->setup();

		$dispatcher = $this->getContainer()->getServer()->getEventDispatcher();

		// first time login event setup
		$dispatcher->addListener(IUser::class . '::firstLogin', function ($event) use ($hm) {
			if ($event instanceof GenericEvent) {
				$hm->firstLogin($event->getSubject());
			}
		});

		// carddav/caldav sync event setup
		$listener = function($event) {
			if ($event instanceof GenericEvent) {
				/** @var BirthdayService $b */
				$b = $this->getContainer()->query(BirthdayService::class);
				$b->onCardChanged(
					$event->getArgument('addressBookId'),
					$event->getArgument('cardUri'),
					$event->getArgument('cardData')
				);
			}
		};

		$dispatcher->addListener('\OCA\DAV\CardDAV\CardDavBackend::createCard', $listener);
		$dispatcher->addListener('\OCA\DAV\CardDAV\CardDavBackend::updateCard', $listener);
		$dispatcher->addListener('\OCA\DAV\CardDAV\CardDavBackend::deleteCard', function($event) {
			if ($event instanceof GenericEvent) {
				/** @var BirthdayService $b */
				$b = $this->getContainer()->query(BirthdayService::class);
				$b->onCardDeleted(
					$event->getArgument('addressBookId'),
					$event->getArgument('cardUri')
				);
			}
		});
	}

	public function getSyncService() {
		return $this->getContainer()->query(SyncService::class);
	}

	private function registerSabrePluginsFromApps(\OCA\DAV\Server $server) {
		\OC_App::loadApps();
		foreach ($this->getContainer()->getServer()->getAppManager()->getInstalledApps() as $app) {
			// FIXME: switch to public API once available
			$appPath = \OC_App::getAppPath($app);
			if($appPath === false) {
				continue;
			}
			// FIXME: switch to public API once available
			// load commands using info.xml
			$info = \OC_App::getAppInfo($app);
			$plugins = $this->loadSabrePluginsFromInfoXml($this->extractPluginList($info));
			foreach ($plugins as $plugin) {
				$server->addPlugin($plugin);
			}
			$collections = $this->loadSabreCollectionsFromInfoXml($this->extractCollectionList($info));
			foreach ($collections as $collection) {
				$server->addCollection($collection);
			}
		}
	}

	private function extractPluginList($array) {
		if (isset($array['sabre']) && is_array($array['sabre'])) {
			if (isset($array['sabre']['plugins']) && is_array($array['sabre']['plugins'])) {
				if (isset($array['sabre']['plugins']['plugin'])) {
					$items = $array['sabre']['plugins']['plugin'];
				   	if (!is_array($items)) {
						$items = [$items];
					}
					return $items;
				}
			}
		}
		return [];
	}

	private function extractCollectionList($array) {
		if (isset($array['sabre']) && is_array($array['sabre'])) {
			if (isset($array['sabre']['collections']) && is_array($array['sabre']['collections'])) {
				if (isset($array['sabre']['collections']['collection'])) {
					$items = $array['sabre']['collections']['collection'];
				   	if (!is_array($items)) {
						$items = [$items];
					}
					return $items;
				}
			}
		}
		return [];
	}

	private function loadSabrePluginsFromInfoXml($plugins) {
		return array_map(function($plugin) {
			try {
				return \OC::$server->query($plugin);
			} catch (QueryException $e) {
				if (class_exists($command)) {
					return new $plugin();
				} else {
					throw new \Exception("Sabre plugin class '$plugin' is unknown and could not be loaded");
				}
			}
		}, $plugins);
	}

	private function loadSabreCollectionsFromInfoXml($plugins) {
		return array_map(function($plugin) {
			try {
				return \OC::$server->query($plugin);
			} catch (QueryException $e) {
				if (class_exists($command)) {
					return new $plugin();
				} else {
					throw new \Exception("Sabre collection class '$plugin' is unknown and could not be loaded");
				}
			}
		}, $plugins);
	}

}
