<?php
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TermsOfService;

use OCA\TermsOfService\AppInfo\Application;
use OCA\TermsOfService\Db\Mapper\SignatoryMapper;
use OCA\TermsOfService\Db\Mapper\TermsMapper;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class Checker {
	/** @var IRequest */
	private $request;
	/** @var IUserSession */
	private $userSession;
	/** @var ISession */
	private $session;
	/** @var SignatoryMapper */
	private $signatoryMapper;
	/** @var TermsMapper */
	private $termsMapper;
	/** @var CountryDetector */
	private $countryDetector;
	/** @var IConfig */
	private $config;
	/** @var IL10N */
	private $l10n;
	/** @var LoggerInterface */
	private $logger;
	/** @var array */
	private $termsCache = [];
	/** @var IURLGenerator */
	private $url;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		ISession $session,
		SignatoryMapper $signatoryMapper,
		TermsMapper $termsMapper,
		CountryDetector $countryDetector,
		IConfig $config,
		IL10N $l10n,
		LoggerInterface $logger,
		IURLGenerator $url
	) {
		$this->request = $request;
		$this->userSession = $userSession;
		$this->session = $session;
		$this->signatoryMapper = $signatoryMapper;
		$this->termsMapper = $termsMapper;
		$this->countryDetector = $countryDetector;
		$this->config = $config;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->url = $url;
	}

	public function getForbiddenMessage(): string {
		return $this->url->getBaseUrl();
	}

	/**
	 * Whether the currently logged-in user has signed the terms and conditions
	 * for the login action
	 *
	 * @return bool
	 */
	public function currentUserHasSigned(): bool {
		$uuid = $this->config->getAppValue(Application::APPNAME, 'term_uuid', '');
		if ($this->userSession->getUser() === null) {
			if ($this->config->getAppValue(Application::APPNAME, 'tos_on_public_shares', '0') === '0') {
				return true;
			}
		} else {
			if ($this->config->getAppValue(Application::APPNAME, 'tos_for_users', '1') !== '1') {
				return true;
			}
		}

		if ($this->isValidWOPIRequest()) {
			// Richdocuments and Collabora doing WOPI requests for the user
			return true;
		}

		if ($this->session->get('term_uuid') === $uuid) {
			return true;
		}

		$countryCode = $this->countryDetector->getCountry();
		if (!array_key_exists($countryCode, $this->termsCache)) {
			$this->termsCache[$countryCode] = $this->termsMapper->getTermsForCountryCode($countryCode);
		}
		if (empty($this->termsCache[$countryCode])) {
			// No terms that would need accepting
			return true;
		}

		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			return false;
		}

		$signatories = $this->signatoryMapper->getSignatoriesByUser($user);
		if (!empty($signatories)) {
			foreach ($signatories as $signatory) {
				foreach ($this->termsCache[$countryCode] as $term) {
					if ((int)$term->getId() === (int)$signatory->getTermsId()) {
						$this->session->set('term_uuid', $uuid);
						return true;
					}
				}
			}
		}

		return false;
	}

	protected function isValidWOPIRequest(): bool {
		return $this->isWOPIRemoteAddress()
			&& $this->isAllowedAppPath()
			&& $this->isAllowedScriptName();
	}

	protected function isAllowedAppPath(): bool {
		return strpos($this->request->getPathInfo(), '/apps/richdocuments/wopi/') === 0
			|| strpos($this->request->getPathInfo(), '/apps/officeonline/wopi/') === 0;
	}

	protected function isAllowedScriptName(): bool {
		return substr($this->request->getScriptName(), 0 - strlen('/index.php')) === '/index.php';
	}

	protected function isWOPIRemoteAddress(): bool {
		$allowedRanges = array_merge(
			$this->allowedRangeForApp('richdocuments'),
			$this->allowedRangeForApp('officeonline')
		);

		$userIp = $this->request->getRemoteAddress();
		foreach ($allowedRanges as $range) {
			try {
				$match = $this->matchCidr($userIp, $range);
			} catch (\Error $e) {
				$this->logger->error('An error occurred while trying to validate a request against the WOPI allow list', ['exception' => $e]);
				continue;
			}

			if ($match) {
				return true;
			}
		}

		return false;
	}

	private function allowedRangeForApp(string $appId): array {
		$allowedRangesString = $this->config->getAppValue($appId, 'wopi_allowlist');
		if ($allowedRangesString === '') {
			return [];
		}
		return explode(',', $allowedRangesString);
	}

	/**
	 * @copyright https://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php-5/594134#594134
	 * @copyright (IPv4) https://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php-5/594134#594134
	 * @copyright (IPv6) MW. https://stackoverflow.com/questions/7951061/matching-ipv6-address-to-a-cidr-subnet via
	 */
	private function matchCidr(string $ip, string $range): bool {
		list($subnet, $bits) = array_pad(explode('/', $range), 2, null);
		if ($bits === null) {
			$bits = 32;
		}
		$bits = (int)$bits;

		if ($this->isIpv4($ip) && $this->isIpv4($subnet)) {
			$mask = -1 << (32 - $bits);

			$ip = ip2long($ip);
			$subnet = ip2long($subnet);
			$subnet &= $mask;
			return ($ip & $mask) === $subnet;
		}

		if ($this->isIpv6($ip) && $this->isIPv6($subnet)) {
			$subnet = inet_pton($subnet);
			$ip = inet_pton($ip);

			$binMask = str_repeat("f", (int)($bits / 4));
			switch ($bits % 4) {
				case 0:
					break;
				case 1:
					$binMask .= "8";
					break;
				case 2:
					$binMask .= "c";
					break;
				case 3:
					$binMask .= "e";
					break;
			}

			$binMask = str_pad($binMask, 32, '0');
			$binMask = pack("H*", $binMask);

			if (($ip & $binMask) === $subnet) {
				return true;
			}
		}
		return false;
	}

	private function isIpv4($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
	}

	private function isIpv6($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
	}
}
