<?php
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TermsOfService\AppInfo;

use Exception;
use OC\Files\Filesystem;
use OC\Files\Storage\Wrapper\Wrapper;
use OCA\Registration\Events\PassedFormEvent;
use OCA\Registration\Events\ShowFormEvent;
use OCA\Registration\Events\ValidateFormEvent;
use OCA\TermsOfService\PublicCapabilities;
use OCA\TermsOfService\Checker;
use OCA\TermsOfService\Dav\CheckPlugin;
use OCA\TermsOfService\Filesystem\StorageWrapper;
use OCA\TermsOfService\Listener\RegistrationIntegration;
use OCA\TermsOfService\Listener\UserDeletedListener;
use OCA\TermsOfService\Notifications\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\SabrePluginEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerExceptionInterface;

include_once __DIR__ . '/../../vendor/autoload.php';

class Application extends App implements IBootstrap {


	public const APPNAME = 'terms_of_service';


	public function __construct() {
		parent::__construct('terms_of_service');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(ShowFormEvent::class, RegistrationIntegration::class);
		$context->registerEventListener(ValidateFormEvent::class, RegistrationIntegration::class);
		$context->registerEventListener(PassedFormEvent::class, RegistrationIntegration::class);
		$context->registerCapability(PublicCapabilities::class);
	}

	public function boot(IBootContext $context): void {
		Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addStorageWrapper');

		$eventDispatcher = $context->getServerContainer()->get(IEventDispatcher::class);
		$eventDispatcher->addListener('OCA\DAV\Connector\Sabre::addPlugin', function (SabrePluginEvent $event) use ($context) {
			$eventServer = $event->getServer();

			if ($eventServer !== null) {
				// We have to register the CheckPlugin here and not info.xml,
				// because info.xml plugins are loaded, after the
				// beforeMethod:* hook has already been emitted.
				$plugin = $context->getAppContainer()->get(CheckPlugin::class);
				$eventServer->addPlugin($plugin);
			}
		});

		$context->injectFn([$this, 'registerNotifier']);
		$context->injectFn([$this, 'createNotificationOnFirstLogin']);
		$context->injectFn([$this, 'registerFrontend']);
	}

	public function registerFrontend(IRequest $request, IConfig $config, IUserSession $userSession): void {
		/** @psalm-suppress UndefinedClass */
		if (!\OC::$CLI) {
			if ($userSession->getUser() instanceof IUser
				&& strpos($request->getPathInfo(), '/s/') !== 0
				&& strpos($request->getPathInfo(), '/login/') !== 0
				&& substr($request->getScriptName(), 0 - strlen('/index.php')) === '/index.php') {
				Util::addScript('terms_of_service', 'terms_of_service-user');
			} else if ($config->getAppValue(self::APPNAME, 'tos_on_public_shares', '0') === '1') {
				Util::addScript('terms_of_service', 'terms_of_service-public');
			}
		}
	}

	public function addStorageWrapper(): void {
		Filesystem::addStorageWrapper(
			'terms_of_service', [$this, 'addStorageWrapperCallback'], -10
		);
	}

	/**
	 * @param string $mountPoint
	 * @param IStorage $storage
	 *
	 * @return StorageWrapper|IStorage
	 * @throws Exception
	 */
	public function addStorageWrapperCallback(string $mountPoint, IStorage $storage): IStorage {
		/** @psalm-suppress UndefinedClass */
		if (!\OC::$CLI) {
			try {
				return new StorageWrapper(
					[
						'storage' => $storage,
						'mountPoint' => $mountPoint,
						'request' => \OC::$server->get(IRequest::class),
						'checker' => \OC::$server->get(Checker::class),
					]
				);
			} catch (ContainerExceptionInterface $e) {
				\OC::$server->get(LoggerInterface::class)->error(
					$e->getMessage(),
					['exception' => $e]
				);
			}
		}

		return $storage;
	}

	public function registerNotifier(IManager $notificationManager): void {
		$notificationManager->registerNotifierService(Notifier::class);
	}

	public function createNotificationOnFirstLogin(IManager $notificationManager, IEventDispatcher $dispatcher): void {
		$dispatcher->addListener(UserFirstTimeLoggedInEvent::class, function(UserFirstTimeLoggedInEvent $event) use ($notificationManager) {
			$user = $event->getUser();
			$notification = $notificationManager->createNotification();
			$notification->setApp('terms_of_service')
				->setDateTime(new \DateTime())
				->setSubject('accept_terms')
				->setObject('terms', '1')
				->setUser($user->getUID());
			$notificationManager->notify($notification);
		});
	}
}
