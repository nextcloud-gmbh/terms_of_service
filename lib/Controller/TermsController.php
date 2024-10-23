<?php
/**
 * @copyright Copyright (c) 2017 Lukas Reschke <lukas@statuscode.ch>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\TermsOfService\Controller;

use OCA\TermsOfService\AppInfo\Application;
use OCA\TermsOfService\Checker;
use OCA\TermsOfService\CountryDetector;
use OCA\TermsOfService\Db\Entities\Terms;
use OCA\TermsOfService\Db\Mapper\CountryMapper;
use OCA\TermsOfService\Db\Mapper\LanguageMapper;
use OCA\TermsOfService\Db\Mapper\SignatoryMapper;
use OCA\TermsOfService\Db\Mapper\TermsMapper;
use OCA\TermsOfService\Exceptions\TermsNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCA\TermsOfService\Events\TermsCreatedEvent;
use OCP\EventDispatcher\IEventDispatcher;

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
								IEventDispatcher $eventDispatcher
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
	 * @PublicPage
	 * @return DataResponse
	 */
	public function index(): DataResponse {
		$currentCountry = $this->countryDetector->getCountry();
		$countryTerms = $this->termsMapper->getTermsForCountryCode($currentCountry);

		if ($this->config->getAppValue(Application::APPNAME, 'term_uuid', '') === '')
		{
			$this->config->getAppValue(Application::APPNAME, 'term_uuid', uniqid());
		}

		$response = [
			'terms' => $countryTerms,
			'languages' => $this->languageMapper->getLanguages(),
			'hasSigned' => $this->checker->currentUserHasSigned(),
		];
		return new DataResponse($response);
	}

	/**
	 * @return DataResponse
	 */
	public function getAdminFormData(): DataResponse {
		$response = [
			'terms' => $this->termsMapper->getTerms(),
			'countries' => $this->countryMapper->getCountries(),
			'languages' => $this->languageMapper->getLanguages(),
			'tos_on_public_shares' => $this->config->getAppValue(Application::APPNAME, 'tos_on_public_shares', '0'),
			'tos_for_users' => $this->config->getAppValue(Application::APPNAME, 'tos_for_users', '1'),
		];
		return new DataResponse($response);
	}

	/**
	 * @param int $id
	 * @return DataResponse
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
	 * @param string $countryCode
	 * @param string $languageCode
	 * @param string $body
	 * @return DataResponse
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
		}

		$event = $this->createTermsCreatedEvent();
		$this->eventDispatcher->dispatchTyped($event);

		return new DataResponse($terms);
	}
}
