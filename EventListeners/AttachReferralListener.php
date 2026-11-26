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

namespace Referral\EventListeners;

use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Referral\Controller\Front\ReferralLinkController;
use Referral\Model\Map\ReferralTableMap;
use Referral\Model\Referral as ReferralModel;
use Referral\Reward\RewardFactory;
use Referral\Reward\RewardIssuerInterface;
use Referral\Service\ReferralCodeChecker;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;

/**
 * Ties a new account to the sponsor whose code was typed on the sign-up form.
 *
 * Runs after the core saved the customer (priority 128). The code is read from the posted form of the
 * main request only, and checked again here: what the form validated is not trusted.
 */
final readonly class AttachReferralListener implements EventSubscriberInterface
{
    /**
     * @param iterable<RewardIssuerInterface> $issuers
     */
    public function __construct(
        private RequestStack $requestStack,
        private ReferralCodeChecker $checker,
        private RewardFactory $rewardFactory,
        #[AutowireIterator('referral.reward_issuer')]
        private iterable $issuers,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::CREATE_CUSTOMER_MINIMAL => ['attach', 64],
        ];
    }

    public function attach(CustomerCreateOrUpdateMinimalEvent $event): void
    {
        $customer = $event->getCustomer();
        $request = $this->requestStack->getMainRequest();
        if (null === $customer || null === $request) {
            return;
        }

        $posted = $request->request->all()[RegisterFormListener::FORM_NAME] ?? null;
        $raw = \is_array($posted) ? ($posted[RegisterFormListener::FIELD] ?? null) : null;
        if (!\is_string($raw) || '' === trim($raw)) {
            return;
        }

        $code = $this->checker->findUsableCode($raw, $customer->getEmail());
        if (null === $code) {
            Tlog::getInstance()->info('Referral: a code was refused at account creation');

            return;
        }

        $connection = Propel::getWriteConnection(ReferralTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $referral = (new ReferralModel())
                ->setSponsorCustomerId($code->getCustomerId())
                ->setReferralCodeId($code->getId())
                ->setRefereeCustomerId($customer->getId())
                ->setStatus('registered');
            $referral->save($connection);

            $customer->setSponsor($code->getCode())->save($connection);

            $reward = $this->rewardFactory->referee($referral);
            $issued = false;
            foreach ($this->issuers as $issuer) {
                if ($issuer->supports($reward->getRewardType())) {
                    $issuer->issue($reward, $customer);
                    $issued = true;
                    break;
                }
            }

            if (!$issued) {
                throw new \LogicException('No issuer for the reward type '.$reward->getRewardType());
            }

            $connection->commit();
        } catch (PropelException $exception) {
            $connection->rollBack();
            // Most likely the unique index of the referee: this account already has its sponsor.
            Tlog::getInstance()->info('Referral: the account could not be attached ('.$exception::class.')');

            return;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            Tlog::getInstance()->error('Referral: attaching an account failed ('.$exception::class.': '.$exception->getMessage().')');

            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->remove(ReferralLinkController::SESSION_KEY);
        }
    }
}
