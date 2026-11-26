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

namespace Referral\Hook;

use Referral\Form\ReferralSettingsForm;
use Referral\Referral;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Translation\Translator;
use Thelia\Tools\URL;

/**
 * Back office: the settings card on the module configuration page and the entry of the tools menu.
 */
class ReferralBackHook extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly SecurityContext $securityContext,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    /**
     * @return array<string, list<array{type: string, method: string}>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
            'main.top-menu-tools' => [
                ['type' => 'back', 'method' => 'onMainTopMenuTools'],
            ],
        ];
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        if (Referral::getModuleCode() !== $event->getArgument('modulecode')) {
            return;
        }

        $event->add($this->render('Referral/configuration.html.twig', [
            'form' => $this->formFactory->createForm(ReferralSettingsForm::getName())->createView()->getView(),
            'referrals_url' => URL::getInstance()->absoluteUrl('/admin/module/Referral/referrals'),
            'can_update' => $this->securityContext->isGranted(['ADMIN'], [AdminResources::MODULE], [Referral::getModuleCode()], [AccessManager::UPDATE]),
        ]));
    }

    public function onMainTopMenuTools(HookRenderBlockEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [AdminResources::MODULE], [Referral::getModuleCode()], [AccessManager::VIEW])) {
            return;
        }

        $event->add([
            'id' => 'tools_menu_referral',
            'class' => '',
            'url' => URL::getInstance()->absoluteUrl('/admin/module/Referral/referrals'),
            'title' => Translator::getInstance()->trans('Referrals', [], 'referral.bo.default'),
        ]);
    }
}
