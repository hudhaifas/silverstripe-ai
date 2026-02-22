<?php

namespace Hudhaifas\AI\Extension;

use Hudhaifas\AI\Model\AIModel;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;

/**
 * SiteConfigAIExtension
 *
 * Extends SiteConfig to store default AI model configuration.
 * Used by MemberAIExtension::getModelAndCalculateCost() to resolve
 * the free and paid tier models.
 */
class SiteConfigAIExtension extends DataExtension {
    private static $has_one = [
        'DefaultFreeAIModel' => AIModel::class,
        'DefaultPaidAIModel' => AIModel::class,
    ];

    public function updateCMSFields(FieldList $fields): void {
        $fields->addFieldsToTab('Root.AI', [
            DropdownField::create(
                'DefaultFreeAIModelID',
                'Default Free Tier Model',
                AIModel::get()->filter(['Active' => true, 'AllowedForFreeCredits' => true])->map('ID', 'Title')
            )->setDescription('Model used when member has only free credits')->setEmptyString('-- Select Model --'),

            DropdownField::create(
                'DefaultPaidAIModelID',
                'Default Paid Tier Model',
                AIModel::get()->filter(['Active' => true])->map('ID', 'Title')
            )->setDescription('Model used when member has purchased credits')->setEmptyString('-- Select Model --'),
        ]);
    }
}
