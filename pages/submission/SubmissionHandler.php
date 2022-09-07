<?php

/**
 * @file pages/submission/SubmissionHandler.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionHandler
 * @ingroup pages_submission
 *
 * @brief Handles page requests to the submission wizard
 */

namespace APP\pages\submission;

use APP\components\forms\publication\LicenseUrlForm;
use APP\components\forms\publication\RelationForm;
use APP\components\forms\publication\TitleAbstractForm;
use APP\components\forms\submission\ReconfigureSubmission;
use APP\components\forms\submission\StartSubmission;
use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\server\Section;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\LazyCollection;
use PKP\components\forms\FormComponent;
use PKP\components\forms\submission\ForTheEditors;
use PKP\context\Context;
use PKP\facades\Locale;
use PKP\pages\submission\PKPSubmissionHandler;
use PKP\plugins\Hook;

class SubmissionHandler extends PKPSubmissionHandler
{
    protected function start(array $args, Request $request): void
    {
        $context = $request->getContext();
        $userGroups = $this->getSubmitUserGroups($context, $request->getUser());
        if (empty($userGroups)) {
            $this->showErrorPage(
                'submission.wizard.notAllowed',
                __('submission.wizard.notAllowed.description', [
                    'email' => $context->getData('contactEmail'),
                    'name' => $context->getData('contactName'),
                ])
            );
            return;
        }

        $sections = $this->getSubmitSections($context);
        if (empty($sections)) {
            $this->showErrorPage(
                'submission.wizard.notAllowed',
                __('submission.wizard.noSectionAllowed.description', [
                    'email' => $context->getData('contactEmail'),
                    'name' => $context->getData('contactName'),
                ])
            );
            return;
        }

        $apiUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $context->getPath(),
            'submissions'
        );

        $form = new StartSubmission($apiUrl, $context, $userGroups, $sections);

        $templateMgr = TemplateManager::getManager($request);

        $templateMgr->setState([
            'form' => $form->getConfig(),
        ]);

        parent::start($args, $request);
    }

    protected function complete(array $args, Request $request, Submission $submission): void
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'canAuthorPublish' => Repo::publication()->canCurrentUserPublish($submission->getId()),
        ]);

        parent::complete($args, $request, $submission);
    }

    protected function getSubmittingTo(Context $context, Submission $submission, array $sections, LazyCollection $categories): string
    {
        $languageCount = count($context->getSupportedSubmissionLocales()) > 1;
        $sectionCount = count($sections) > 1;
        $section = collect($sections)->first(fn ($section) => $section->getId() === $submission->getCurrentPublication()->getData('sectionId'));

        if ($sectionCount && $languageCount) {
            return __(
                'submission.wizard.submittingToSectionInLanguage',
                [
                    'section' => $section->getLocalizedTitle(),
                    'language' => Locale::getMetadata($submission->getData('locale'))->getDisplayName(),
                ]
            );
        } elseif ($sectionCount) {
            return __(
                'submission.wizard.submittingToSection',
                [
                    'section' => $section->getLocalizedTitle(),
                ]
            );
        } elseif ($languageCount) {
            return __(
                'submission.wizard.submittingInLanguage',
                [
                    'language' => Locale::getMetadata($submission->getData('locale'))->getDisplayName(),
                ]
            );
        }
        return '';
    }

    protected function getReconfigureForm(Context $context, Submission $submission, Publication $publication, array $sections, LazyCollection $categories): ReconfigureSubmission
    {
        return new ReconfigureSubmission(
            FormComponent::ACTION_EMIT,
            $submission,
            $publication,
            $context,
            $sections
        );
    }

    protected function getTitleAbstractForm(string $publicationApiUrl, array $locales, Publication $publication, Context $context, array $sections): TitleAbstractForm
    {
        /** @var Section $section */
        $section = collect($sections)->first(fn ($section) => $section->getId() === $publication->getData('sectionId'));

        return new TitleAbstractForm(
            $publicationApiUrl,
            $locales,
            $publication,
            $section,
            true
        );
    }

    protected function getEditorsStep(Request $request, Submission $submission, Publication $publication, array $locales, string $publicationApiUrl): array
    {
        $step = parent::getEditorsStep($request, $submission, $publication, $locales, $publicationApiUrl);

        $licenseForm = new LicenseUrlForm(
            'licenseUrl',
            'PUT',
            $publicationApiUrl,
            $publication,
            Application::get()->getRequest()->getContext(),
        );
        $relationForm = new RelationForm($publicationApiUrl, $publication);

        $newSections = [
            [
                'id' => $licenseForm->id,
                'name' => __('submission.license'),
                'type' => self::SECTION_TYPE_FORM,
                'description' => __('submission.licenseSection.description'),
                'form' => $licenseForm->getConfig(),
            ],
            [
                'id' => $relationForm->id,
                'name' => __('publication.relation.label'),
                'type' => self::SECTION_TYPE_FORM,
                'description' => __('publication.relation.description'),
                'form' => $relationForm->getConfig(),
            ],
        ];

        array_splice($step['sections'], (count($step['sections']) - 1), 0, $newSections);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->setState([
            'i18nRelationWithLink' => __('publication.relation.publishedWithLink'),
            'licenses' => collect($licenseForm->licenseOptions)
                ->mapWithKeys(fn ($license) => [$license['value'] => $license['label']])
                ->toArray(),
        ]);

        Hook::add('Template::SubmissionWizard::Section::Review', function (string $hookName, array $params) {
            $step = $params[0]['step']; /** @var string $step */
            $templateMgr = $params[1]; /** @var TemplateManager $templateMgr */
            $output = & $params[2]; /** @var string $output */

            if ($step === 'editors') {
                $output .= $templateMgr->fetch('submission/review-license.tpl');
                $output .= $templateMgr->fetch('submission/review-relation.tpl');
            }

            return false;
        });

        return $step;
    }

    protected function getForTheEditorsForm(string $publicationApiUrl, array $locales, Publication $publication, Submission $submission, Context $context, string $suggestionUrlBase): ForTheEditors
    {
        return new ForTheEditors(
            $publicationApiUrl,
            $locales,
            $publication,
            $submission,
            $context,
            $suggestionUrlBase
        );
    }

    protected function getReconfigurePublicationProps(): array
    {
        return [
            'sectionId',
        ];
    }

    protected function getReconfigureSubmissionProps(): array
    {
        return [
            'locale',
        ];
    }
}
