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

namespace Referral\Form;

use Referral\Referral;
use Referral\Service\BackOfficeTranslation;
use Referral\Service\ReferralSettings;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Form\BaseForm;

/**
 * The settings of the program, as the module configuration page edits them.
 *
 * The back-office theme never shows the help of a checkbox: what a box does is said in its label.
 */
class ReferralSettingsForm extends BaseForm
{
    public static function getName(): string
    {
        return 'referral_settings_form';
    }

    protected function buildForm(): void
    {
        $settings = new ReferralSettings();

        $this->formBuilder
            ->add(Referral::CONFIG_ENABLED, CheckboxType::class, [
                'required' => false,
                'data' => $settings->isEnabled(),
                'label' => $this->translate('Program enabled: a referral code is accepted at sign-up and rewards are issued'),
            ])
            ->add(Referral::CONFIG_REFEREE_VALUE_TYPE, ChoiceType::class, $this->typeOptions($settings, Referral::CONFIG_REFEREE_VALUE_TYPE, 'Referee coupon type'))
            ->add(Referral::CONFIG_REFEREE_VALUE, NumberType::class, $this->valueOptions($settings, Referral::CONFIG_REFEREE_VALUE, Referral::CONFIG_REFEREE_VALUE_TYPE, 'Referee coupon value', 'An amount in the shop currency, or a percentage (at most 100) when the type is a percentage.'))
            ->add(Referral::CONFIG_REFEREE_MINIMUM_AMOUNT, NumberType::class, $this->moneyOptions($settings, Referral::CONFIG_REFEREE_MINIMUM_AMOUNT, 'Referee coupon: minimum cart', '0 = no minimum.'))
            ->add(Referral::CONFIG_SPONSOR_VALUE_TYPE, ChoiceType::class, $this->typeOptions($settings, Referral::CONFIG_SPONSOR_VALUE_TYPE, 'Sponsor coupon type'))
            ->add(Referral::CONFIG_SPONSOR_VALUE, NumberType::class, $this->valueOptions($settings, Referral::CONFIG_SPONSOR_VALUE, Referral::CONFIG_SPONSOR_VALUE_TYPE, 'Sponsor coupon value', 'An amount in the shop currency, or a percentage (at most 100) when the type is a percentage.'))
            ->add(Referral::CONFIG_SPONSOR_MINIMUM_AMOUNT, NumberType::class, $this->moneyOptions($settings, Referral::CONFIG_SPONSOR_MINIMUM_AMOUNT, 'Sponsor coupon: minimum cart', '0 = no minimum.'))
            ->add(Referral::CONFIG_COUPON_VALIDITY_DAYS, IntegerType::class, [
                'required' => true,
                'data' => (int) $settings->get(Referral::CONFIG_COUPON_VALIDITY_DAYS),
                'constraints' => [new NotBlank(message: $this->translate('This value should not be blank.')), new Range(min: 1, notInRangeMessage: $this->translate('This value should be 1 or more.'))],
                'label' => $this->translate('Coupon validity (days)'),
                'label_attr' => ['help' => $this->translate('At least 1 day, counted from the moment the coupon is issued.')],
            ])
            ->add(Referral::CONFIG_TRIGGER_STATUS, ChoiceType::class, [
                'required' => true,
                'data' => $settings->get(Referral::CONFIG_TRIGGER_STATUS),
                'choices' => [
                    $this->translate('Paid (paid, processing or sent)') => 'paid',
                    $this->translate('Sent') => 'sent',
                ],
                'constraints' => [new NotBlank(message: $this->translate('This value should not be blank.')), new Choice(choices: ['paid', 'sent'], message: $this->translate('This value is not a valid choice.'))],
                'label' => $this->translate('Order status that triggers the rewards'),
                'label_attr' => ['help' => $this->translate('The first order of the referee triggers the sponsor reward when it reaches this status.')],
            ])
            ->add(Referral::CONFIG_MONTHLY_CAP, IntegerType::class, [
                'required' => true,
                'data' => (int) $settings->get(Referral::CONFIG_MONTHLY_CAP),
                'constraints' => [new NotBlank(message: $this->translate('This value should not be blank.')), new PositiveOrZero(message: $this->translate('This value should be 0 or more.'))],
                'label' => $this->translate('Rewards per sponsor and per month (0 = unlimited)'),
                'label_attr' => ['help' => $this->translate('Calendar month. 0 = no limit.')],
            ])
            ->add(Referral::CONFIG_CANCEL_ON_REFUND, CheckboxType::class, [
                'required' => false,
                'data' => '1' === $settings->get(Referral::CONFIG_CANCEL_ON_REFUND),
                'label' => $this->translate('Cancel the rewards when the order that triggered them is cancelled or refunded'),
            ])
            ->add(Referral::CONFIG_SAME_ADDRESS_CHECK, CheckboxType::class, [
                'required' => false,
                'data' => '1' === $settings->get(Referral::CONFIG_SAME_ADDRESS_CHECK),
                'label' => $this->translate('Refuse the reward when the referee is delivered to one of the sponsor\'s addresses'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function typeOptions(ReferralSettings $settings, string $name, string $label): array
    {
        return [
            'required' => true,
            'data' => $settings->get($name),
            'choices' => [
                $this->translate('Fixed amount') => 'amount',
                $this->translate('Percentage') => 'percent',
            ],
            'constraints' => [new NotBlank(message: $this->translate('This value should not be blank.')), new Choice(choices: ['amount', 'percent'], message: $this->translate('This value is not a valid choice.'))],
            'label' => $this->translate($label),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moneyOptions(ReferralSettings $settings, string $name, string $label, string $help): array
    {
        return [
            'required' => true,
            'scale' => 2,
            'data' => (float) $settings->get($name),
            'constraints' => [new NotBlank(message: $this->translate('This value should not be blank.')), new PositiveOrZero(message: $this->translate('This value should be 0 or more.'))],
            'label' => $this->translate($label),
            'label_attr' => ['help' => $this->translate($help)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valueOptions(ReferralSettings $settings, string $name, string $typeField, string $label, string $help): array
    {
        $percentIsTooHigh = $this->translate('A percentage cannot exceed 100.');

        return [
            'required' => true,
            'scale' => 2,
            'data' => (float) $settings->get($name),
            'constraints' => [
                new NotBlank(message: $this->translate('This value should not be blank.')),
                new Positive(message: $this->translate('This value should be greater than 0.')),
                new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($typeField, $percentIsTooHigh): void {
                    $root = $context->getRoot();
                    if ($root instanceof \Symfony\Component\Form\FormInterface && 'percent' === $root->get($typeField)->getData() && (float) $value > 100) {
                        $context->buildViolation($percentIsTooHigh)->addViolation();
                    }
                }),
            ],
            'label' => $this->translate($label),
            'label_attr' => ['help' => $this->translate($help)],
        ];
    }

    private function translate(string $identifier): string
    {
        return BackOfficeTranslation::trans($identifier, $this->request);
    }
}
