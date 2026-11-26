<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Referral\Controller\Back;

use Referral\Admin\ReferralListReader;
use Referral\Form\ReferralSettingsForm;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Reward\RewardCanceller;
use Referral\Reward\SponsorRewardEngine;
use Referral\Service\BackOfficeTranslation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Currency;
use Thelia\Tools\TokenProvider;
use Thelia\Tools\URL;
use Twig\Environment;

/**
 * The back office of the program: the settings (saved from the module configuration page), the list of
 * referrals with their rewards, and the manual cancellation of a reward.
 *
 * Viewing needs the VIEW right on the module, saving and cancelling the UPDATE one.
 */
#[Route('/admin/module/Referral', name: 'referral.bo')]
class ReferralBackOfficeController extends BaseAdminController
{
    public const PAGE_SIZE = 20;

    public const STATUSES = [
        SponsorRewardEngine::STATUS_REGISTERED,
        SponsorRewardEngine::STATUS_REWARDED,
        SponsorRewardEngine::STATUS_CAPPED,
        SponsorRewardEngine::STATUS_CANCELLED,
    ];

    private const TEMPLATE = '@ReferralModule/backOffice/default-twig/Referral/referrals.html.twig';
    private const REASON_MAX_LENGTH = 255;

    #[Route('/settings', name: '.settings.save', methods: ['POST'])]
    public function saveSettings(): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [Referral::getModuleCode()], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ReferralSettingsForm::getName());

        try {
            $data = $this->validateForm($form)->getData();

            foreach (Referral::DEFAULTS as $name => $default) {
                $value = $data[$name] ?? null;
                Referral::setConfigValue($name, match (true) {
                    \is_bool($value) => $value ? '1' : '0',
                    null === $value => '0',
                    default => (string) $value,
                });
            }

            $this->flash('success', 'The referral settings are saved.');
        } catch (FormValidationException $exception) {
            $this->flash('error', $this->createStandardFormValidationErrorMessage($exception));
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl('/admin/module/'.Referral::getModuleCode()));
    }

    #[Route('/referrals', name: '.list', methods: ['GET'])]
    public function list(Request $request, Environment $twig, ReferralListReader $reader): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [Referral::getModuleCode()], AccessManager::VIEW)) {
            return $response;
        }

        $status = (string) $request->query->get('status', '');
        $status = \in_array($status, self::STATUSES, true) ? $status : null;
        $result = $reader->read($status, max(1, $request->query->getInt('page', 1)), self::PAGE_SIZE);

        return new Response($twig->render(self::TEMPLATE, [
            'counters' => $result['counters'],
            'rows' => $result['rows'],
            'total' => $result['total'],
            'current_page' => $result['page'],
            'pages' => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'status' => $status,
            'statuses' => self::STATUSES,
            'monthly_cap' => (int) Referral::getConfigValue(Referral::CONFIG_MONTHLY_CAP),
            'currency_symbol' => Currency::getDefaultCurrency()->getSymbol(),
            'can_update' => $this->isGrantedTo(AccessManager::UPDATE),
        ]));
    }

    #[Route('/rewards/{reward_id}/cancel', name: '.reward.cancel', methods: ['POST'], requirements: ['reward_id' => '\d+'])]
    public function cancel(int $reward_id, Request $request, TokenProvider $tokens, RewardCanceller $canceller): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [Referral::getModuleCode()], AccessManager::UPDATE)) {
            return $response;
        }

        $tokens->checkToken((string) $request->request->get('_token', ''));

        $reward = ReferralRewardQuery::create()->findPk($reward_id);
        $reason = trim((string) $request->request->get('reason', ''));

        if (null === $reward) {
            $this->flash('error', 'This reward does not exist.');
        } elseif ('' === $reason || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $this->flash('error', 'A reason of 1 to 255 characters is required to cancel a reward.');
        } elseif ('issued' !== $reward->getStatus()) {
            $this->flash('error', 'Only an issued reward can be cancelled.');
        } else {
            $canceller->cancel($reward, $reason);
            $this->flash('success', 'The reward is cancelled.');
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl('/admin/module/Referral/referrals'));
    }

    private function isGrantedTo(string $access): bool
    {
        return $this->getSecurityContext()->isGranted(['ADMIN'], [AdminResources::MODULE], [Referral::getModuleCode()], [$access]);
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add($type, BackOfficeTranslation::trans($message, $this->getRequest()));
    }
}
