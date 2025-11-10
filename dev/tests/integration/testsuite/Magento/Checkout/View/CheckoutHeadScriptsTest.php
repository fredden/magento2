<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace  Magento\Checkout\View;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

class CheckoutHeadScriptsTest extends AbstractController
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var QuoteFactory
     */
    private QuoteFactory $quoteFactory;

    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $cartRepository;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $om = Bootstrap::getObjectManager();
        $state = $om->get(State::class);
        $state->setAreaCode(Area::AREA_FRONTEND);

        $this->productRepository = $om->get(ProductRepositoryInterface::class);
        $this->quoteFactory      = $om->get(QuoteFactory::class);
        $this->cartRepository    = $om->get(CartRepositoryInterface::class);
        $this->checkoutSession   = $om->get(CheckoutSession::class);
        $this->storeManager      = $om->get(StoreManagerInterface::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store checkout/options/guest_checkout 1
     */
    public function testCheckoutHtmlContainsBaseRequireHelpersViaDispatch(): void
    {
        $this->prepareGuestQuoteWithItem();

        $this->dispatch('checkout/cart');
        $body = $this->getResponse()->getBody();

        $this->assertNotEmpty($body, 'Expected checkout page HTML after dispatch.');

        $this->assertStringContainsString(
            'mage/requirejs/baseUrlResolver.js',
            $body,
            'baseUrlResolver.js should be present in checkout HTML <head>.'
        );
        $this->assertStringContainsString(
            'mage/requirejs/mixins.js',
            $body,
            'mixins.js should be present in checkout HTML <head>.'
        );

        // Accept both base or frontend buckets
        $this->assertMatchesRegularExpression(
            '#/static/[^"]*/(base|frontend)/Magento/[^/]+/[A-Za-z_]+/mage/requirejs/baseUrlResolver\.js#',
            $body,
            'baseUrlResolver.js should be served from either base/Magento/base/<locale> or frontend/Magento/<theme>/<locale>.'
        );

        $this->assertMatchesRegularExpression(
            '#/static/[^"]*/(base|frontend)/Magento/[^/]+/[A-Za-z_]+/mage/requirejs/mixins\.js#',
            $body,
            'mixins.js should be served from either base/Magento/base/<locale> or frontend/Magento/<theme>/<locale>.'
        );
    }

    /**
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function prepareGuestQuoteWithItem(): void
    {
        $store   = $this->storeManager->getStore();
        /** @var \Magento\Catalog\Api\Data\ProductInterface $product */
        $product = $this->productRepository->get('simple');

        /** @var Quote $quote */
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setIsActive(true);
        $quote->setIsMultiShipping(0);
        $quote->setCustomerIsGuest(true);

        $quote->getBillingAddress()->setEmail('guest@example.com');
        $quote->getShippingAddress()
            ->setCollectShippingRates(true)
            ->collectShippingRates();

        $quote->addProduct($product, 1);
        $quote->setTotalsCollectedFlag(false)->collectTotals();

        $this->cartRepository->save($quote);

        // Put quote into checkout session so controller sees it
        $this->checkoutSession->clearStorage();
        $this->checkoutSession->replaceQuote($quote);
        $this->checkoutSession->setQuoteId($quote->getId());
        $this->checkoutSession->setLoadInactive(false);
    }
}
