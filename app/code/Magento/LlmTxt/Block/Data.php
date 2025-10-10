<?php
/**
 * Copyright © Mage-OS, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);


namespace Magento\LlmTxt\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\LlmTxt\Model\Config;
use Magento\LlmTxt\Model\Generator;

class Data extends AbstractBlock implements IdentityInterface
{
    private const CACHE_TAG = 'llmtxt';

    public function __construct(
        Context $context,
        private readonly Generator $generator,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();

            // Check if module is enabled
            if (!$this->config->isEnabled($storeId)) {
                return '';
            }

            return $this->generator->generate($storeId) . PHP_EOL;
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getIdentities(): array
    {
        return [
            self::CACHE_TAG . '_' . $this->storeManager->getStore()->getId(),
        ];
    }
}
