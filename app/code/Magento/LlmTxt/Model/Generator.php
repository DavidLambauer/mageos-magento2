<?php
/**
 * Copyright © Mage-OS, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);


namespace Magento\LlmTxt\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

final class Generator
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function generate(?int $storeId = null): string
    {
        $storeId = $storeId ?? (int) $this->storeManager->getStore()->getId();
        $content = [];

        // H1: Site Name
        $siteName = $this->config->getSiteName($storeId);
        if (empty($siteName)) {
            $siteName = (string) $this->storeManager->getStore($storeId)->getName();
        }
        $content[] = "# {$siteName}";
        $content[] = '';

        // Blockquote: Site Description
        $siteDescription = $this->config->getSiteDescription($storeId);
        if (!empty($siteDescription)) {
            $content[] = "> {$siteDescription}";
            $content[] = '';
        }

        // Custom Content
        $customContent = $this->config->getCustomContent($storeId);
        if (!empty($customContent)) {
            $content[] = trim($customContent);
            $content[] = '';
        }

        // Auto-generate sections
        if ($this->config->shouldAutoIncludeCategories($storeId)) {
            $categoriesSection = $this->generateCategoriesSection($storeId);
            if (!empty($categoriesSection)) {
                $content[] = $categoriesSection;
                $content[] = '';
            }
        }

        if ($this->config->getFeaturedProductsCount($storeId) > 0) {
            $productsSection = $this->generateProductsSection($storeId);
            if (!empty($productsSection)) {
                $content[] = $productsSection;
                $content[] = '';
            }
        }

        if ($this->config->shouldAutoIncludeCmsPages($storeId)) {
            $cmsSection = $this->generateCmsSection($storeId);
            if (!empty($cmsSection)) {
                $content[] = $cmsSection;
                $content[] = '';
            }
        }

        return trim(implode("\n", $content));
    }

    private function generateCategoriesSection(int $storeId): string
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'meta_description', 'description'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gt' => 1])
            ->setStoreId($storeId)
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC')
            ->setPageSize(10);

        $lines = ['## Categories'];
        $baseUrl = $this->getBaseUrl($storeId);
        $maxLength = $this->config->getMaxDescriptionLength($storeId);

        foreach ($collection as $category) {
            try {
                $name = (string) $category->getName();
                $url = $baseUrl . $category->getUrlKey() . '.html';
                $description = $this->cleanDescription(
                    (string) ($category->getMetaDescription() ?: $category->getDescription()),
                    $maxLength
                );

                if (!empty($description)) {
                    $lines[] = "- [{$name}]({$url}): {$description}";
                } else {
                    $lines[] = "- [{$name}]({$url})";
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function generateProductsSection(int $storeId): string
    {
        $limit = $this->config->getFeaturedProductsCount($storeId);

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'short_description', 'meta_description'])
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('visibility', ['in' => [2, 3, 4]])
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->setOrder('created_at', 'DESC')
            ->setPageSize($limit);

        $lines = ['## Featured Products'];
        $baseUrl = $this->getBaseUrl($storeId);
        $maxLength = $this->config->getMaxDescriptionLength($storeId);

        foreach ($collection as $product) {
            try {
                $name = (string) $product->getName();
                $url = $baseUrl . $product->getUrlKey() . '.html';
                $description = $this->cleanDescription(
                    (string) ($product->getMetaDescription() ?: $product->getShortDescription()),
                    $maxLength
                );

                if (!empty($description)) {
                    $lines[] = "- [{$name}]({$url}): {$description}";
                } else {
                    $lines[] = "- [{$name}]({$url})";
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function generateCmsSection(int $storeId): string
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('is_active', 1)
            ->addFilter('store_id', [$storeId, 0], 'in')
            ->create();

        try {
            $pages = $this->pageRepository->getList($searchCriteria)->getItems();
        } catch (NoSuchEntityException $e) {
            return '';
        }

        $lines = ['## Information'];
        $baseUrl = $this->getBaseUrl($storeId);
        $maxLength = $this->config->getMaxDescriptionLength($storeId);

        // Prioritize common pages
        $priorityIdentifiers = ['about-us', 'contact-us', 'customer-service', 'shipping', 'returns'];
        $sortedPages = [];

        foreach ($pages as $page) {
            $identifier = (string) $page->getIdentifier();
            if ($identifier === 'home' || $identifier === 'no-route') {
                continue;
            }

            $priority = array_search($identifier, $priorityIdentifiers, true);
            if ($priority !== false) {
                $sortedPages[$priority] = $page;
            } else {
                $sortedPages[100 + count($sortedPages)] = $page;
            }
        }

        ksort($sortedPages);
        $count = 0;
        $maxPages = 10;

        foreach ($sortedPages as $page) {
            if ($count >= $maxPages) {
                break;
            }

            try {
                $title = (string) $page->getTitle();
                $identifier = (string) $page->getIdentifier();
                $url = $baseUrl . $identifier;
                $description = $this->cleanDescription(
                    (string) $page->getMetaDescription(),
                    $maxLength
                );

                if (!empty($description)) {
                    $lines[] = "- [{$title}]({$url}): {$description}";
                } else {
                    $lines[] = "- [{$title}]({$url})";
                }
                $count++;
            } catch (\Exception $e) {
                continue;
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function getBaseUrl(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (NoSuchEntityException $e) {
            return '';
        }
    }

    private function cleanDescription(string $description, int $maxLength): string
    {
        // Strip HTML tags
        $description = strip_tags($description);

        // Remove excessive whitespace
        $description = preg_replace('/\s+/', ' ', $description) ?? '';

        // Trim
        $description = trim($description);

        // Truncate if needed
        if (mb_strlen($description) > $maxLength) {
            $description = mb_substr($description, 0, $maxLength - 3) . '...';
        }

        return $description;
    }

    public function estimateTokenCount(string $content): int
    {
        // Rough estimation: 1 token ≈ 0.75 words
        $wordCount = str_word_count($content);
        return (int) ceil($wordCount * 1.3);
    }
}
