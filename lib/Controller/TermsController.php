<?php
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TermsOfService\Controller;

use OCA\TermsOfService\AppInfo\Application;
use OCA\TermsOfService\BackgroundJobs\CreateNotifications;
use OCA\TermsOfService\Checker;
use OCA\TermsOfService\CountryDetector;
use OCA\TermsOfService\Db\Entities\Terms;
use OCA\TermsOfService\Db\Mapper\CountryMapper;
use OCA\TermsOfService\Db\Mapper\LanguageMapper;
use OCA\TermsOfService\Db\Mapper\SignatoryMapper;
use OCA\TermsOfService\Db\Mapper\TermsMapper;
use OCA\TermsOfService\Exceptions\TermsNotFoundException;
use OCA\TermsOfService\ResponseDefinitions;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCA\TermsOfService\Events\TermsCreatedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * @psalm-import-type TermsOfServiceAdminFormData from ResponseDefinitions
 * @psalm-import-type TermsOfServiceTerms from ResponseDefinitions
 */
class TermsController extends OCSController {
	/** @var IFactory */
	private $factory;
	/** @var TermsMapper */
	private $termsMapper;
	/** @var SignatoryMapper */
	private $signatoryMapper;
	/** @var CountryMapper */
	private $countryMapper;
	/** @var LanguageMapper */
	private $languageMapper;
	/** @var CountryDetector */
	private $countryDetector;
	/** @var Checker */
	private $checker;
	/** @var IConfig */
	private $config;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	public function __construct(string $appName,
								IRequest $request,
								IFactory $factory,
								TermsMapper $termsMapper,
								SignatoryMapper $signatoryMapper,
								CountryMapper $countryMapper,
								LanguageMapper $languageMapper,
								CountryDetector $countryDetector,
								Checker $checker,
								IConfig $config,
								IEventDispatcher $eventDispatcher,
								protected IJobList $jobList,
	) {
		parent::__construct($appName, $request);
		$this->factory = $factory;
		$this->termsMapper = $termsMapper;
		$this->signatoryMapper = $signatoryMapper;
		$this->countryMapper = $countryMapper;
		$this->languageMapper = $languageMapper;
		$this->countryDetector = $countryDetector;
		$this->checker = $checker;
		$this->config = $config;
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * Get all available terms for the current country
	 *
	 * @return DataResponse<Http::STATUS_OK, array{terms: list<TermsOfServiceTerms>, languages: array<string, string>, hasSigned: bool}, array{}>
	 *
	 * 200: Get list successfully
	 */
	#[PublicPage]
	public function index(): DataResponse {
		$currentCountry = $this->countryDetector->getCountry();
		$countryTerms = $this->termsMapper->getTermsForCountryCode($currentCountry);

		if ($this->config->getAppValue(Application::APPNAME, 'term_uuid', '') === '')
		{
			$this->config->setAppValue(Application::APPNAME, 'term_uuid', uniqid());
		}

		$response = [
			'terms' => array_map(static fn(Terms $terms): array => $terms->jsonSerialize(), $countryTerms),
			'languages' => $this->languageMapper->getLanguages(),
			'hasSigned' => $this->checker->currentUserHasSigned(),
		];
		return new DataResponse($response);
	}

	/**
	 * Get the form data for the admin interface
	 *
	 * @return DataResponse<Http::STATUS_OK, TermsOfServiceAdminFormData, array{}>
	 *
	 * 200: Get form data successfully
	 */
	public function getAdminFormData(): DataResponse {
		$forPublicShares = $this->config->getAppValue(Application::APPNAME, 'tos_on_public_shares', '0');
		if ($forPublicShares !== '0') {
			$forPublicShares = '1';
		}
		$forUsers = $this->config->getAppValue(Application::APPNAME, 'tos_for_users', '1');
		if ($forUsers !== '1') {
			$forUsers = '0';
		}
		$response = [
			'terms' => array_map(static fn(Terms $terms): array => $terms->jsonSerialize(), $this->termsMapper->getTerms()),
			'countries' => $this->countryMapper->getCountries(),
			'languages' => $this->languageMapper->getLanguages(),
			'tos_on_public_shares' => $forPublicShares,
			'tos_for_users' => $forUsers,
		];
		return new DataResponse($response);
	}

	/**
	 * Delete a given Term by id
	 *
	 * @param positive-int $id The terms which should be deleted
	 * @return DataResponse<Http::STATUS_OK, array<empty>, array{}>
	 *
	 * 200: Deleted successfully
	 */
	public function destroy(int $id): DataResponse {
		$terms = new Terms();
		$terms->setId($id);

		$this->termsMapper->delete($terms);
		$this->signatoryMapper->deleteTerm($terms);

		return new DataResponse();
	}

	protected function createTermsCreatedEvent(): TermsCreatedEvent {
		return new TermsCreatedEvent();
	}

	/**
	 * Create new terms
	 *
	 * @param string $countryCode One of the 2-letter region codes or `--` for "global"
	 * @param string $languageCode One of the 2-letter language codes
	 * @param string $body The actual terms and conditions text (can be markdown, using headers, basic text formating, lists and links)
	 * @return DataResponse<Http::STATUS_OK, TermsOfServiceTerms, array{}>|DataResponse<Http::STATUS_EXPECTATION_FAILED, array<empty>, array{}>
	 *
	 * 200: Created successfully
	 * 417: Country or language code was not a valid option
	 */
	public function create(string $countryCode,
						   string $languageCode,
						   string $body): DataResponse {
		$update = false;
		try {
			// Update terms
			$terms = $this->termsMapper->getTermsForCountryCodeAndLanguageCode($countryCode, $languageCode);
			$update = true;
		} catch (TermsNotFoundException $e) {
			// Create new terms
			$terms = new Terms();
		}

		if (!$this->countryMapper->isValidCountry($countryCode) || !$this->languageMapper->isValidLanguage($languageCode)) {
			return new DataResponse([], Http::STATUS_EXPECTATION_FAILED);
		}

		$terms->setCountryCode($countryCode);
		$terms->setLanguageCode($languageCode);
		$terms->setBody($body);

		if($update === true) {
			$this->termsMapper->update($terms);
		} else {
			$this->termsMapper->insert($terms);
			$this->jobList->add(CreateNotifications::class);
		}

		$event = $this->createTermsCreatedEvent();
		$this->eventDispatcher->dispatchTyped($event);

		return new DataResponse($terms->jsonSerialize());
	}
}
