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

use Referral\Controller\Front\ReferralLinkController;
use Referral\Referral;
use Referral\Service\ReferralCodeChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;

/**
 * Adds the optional "referral code" field to the sign-up form of the shop, prefilled from the link
 * the visitor came by. Every refusal says the same thing, whether the code exists or not.
 */
final readonly class RegisterFormListener implements EventSubscriberInterface
{
    public const FIELD = 'referral_code';
    public const FORM_NAME = 'flexybundle_form_customer_register_form';
    public const MAX_LENGTH = 20;

    public function __construct(
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
        private ReferralCodeChecker $checker,
        #[Autowire(service: 'limiter.referral_code_check')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::FORM_AFTER_BUILD.'.'.self::FORM_NAME => ['addField', 128],
        ];
    }

    public function addField(TheliaFormEvent $event): void
    {
        $remembered = $this->requestStack->getMainRequest()?->getSession()->get(ReferralLinkController::SESSION_KEY);

        $event->getForm()->getFormBuilder()->add(self::FIELD, TextType::class, [
            'required' => false,
            'label' => $this->translator->trans('Referral code', [], Referral::MESSAGE_DOMAIN),
            'data' => \is_string($remembered) ? $remembered : null,
            'attr' => ['maxlength' => self::MAX_LENGTH, 'autocomplete' => 'off'],
            'constraints' => [
                new Length(max: self::MAX_LENGTH),
                new Callback($this->validate(...)),
            ],
        ]);
    }

    public function validate(mixed $value, ExecutionContextInterface $context): void
    {
        if (null === $value || '' === trim((string) $value)) {
            return;
        }

        $key = $this->requestStack->getMainRequest()?->getClientIp() ?? 'unknown';
        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            $context->addViolation($this->translator->trans('Too many attempts, please try again later.', [], Referral::MESSAGE_DOMAIN));

            return;
        }

        $root = $context->getRoot();
        $email = $root instanceof \Symfony\Component\Form\FormInterface && $root->has('email') ? $root->get('email')->getData() : null;

        if (null === $this->checker->findUsableCode((string) $value, \is_string($email) ? $email : null)) {
            $context->addViolation($this->translator->trans('This referral code cannot be used.', [], Referral::MESSAGE_DOMAIN));
        }
    }
}
