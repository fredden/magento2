<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Fedex\Model;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Customer\Test\Fixture\Customer;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\CustomerCart;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use Magento\Shipping\Model\CarrierFactory;
use Magento\Quote\Model\QuoteManagement;

/**
 * Integration test for FedEx shipping label creation.
 *
 * Tests the backend logic of:
 * - Creating shipment with FedEx carrier
 * - Adding packages to shipment
 * - Storing and retrieving tracking information
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CreateShippingLabelTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var ShipmentRepositoryInterface
     */
    private $shipmentRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var ShipmentFactory
     */
    private $shipmentFactory;

    /**
     * @var DataFixtureStorage
     */
    private $fixtures;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->shipmentRepository = $this->objectManager->get(ShipmentRepositoryInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->shipmentFactory = $this->objectManager->get(ShipmentFactory::class);
        $this->fixtures = $this->objectManager->get(DataFixtureStorageManager::class)->getStorage();
    }

    /**
     * Place order from cart and return order entity.
     *
     * @param mixed $cart
     * @return Order
     */
    private function placeOrderFromCart($cart): Order
    {
        $quoteManagement = $this->objectManager->get(QuoteManagement::class);
        $orderId = $quoteManagement->placeOrder($cart->getId());
        return $this->orderRepository->get($orderId);
    }

    /**
     * Create shipment for order with all items.
     *
     * @param Order $order
     * @return Shipment
     */
    private function createShipmentForOrder(Order $order): Shipment
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[$item->getId()] = $item->getQtyOrdered();
        }
        return $this->shipmentFactory->create($order, $items);
    }

    /**
     * Build package data for shipment.
     *
     * @param array $orderItemsArray
     * @param object $product1
     * @param object $product2
     * @return array
     */
    private function buildMultiPackageData(array $orderItemsArray, $product1, $product2): array
    {
        return [
            1 => [
                'params' => [
                    'container' => 'YOUR_PACKAGING', 'weight' => 5.0, 'weight_units' => 'LB',
                    'length' => 10, 'width' => 8, 'height' => 6, 'dimension_units' => 'IN',
                    'customs_value' => 100.00, 'delivery_confirmation' => 'SIGNATURE',
                ],
                'items' => [[
                    'qty' => 1, 'customs_value' => 50.00, 'price' => 50.00,
                    'name' => $product1->getName(), 'weight' => 2.5,
                    'product_id' => $product1->getId(), 'order_item_id' => $orderItemsArray[0]->getId(),
                ]],
            ],
            2 => [
                'params' => [
                    'container' => 'YOUR_PACKAGING', 'weight' => 3.0, 'weight_units' => 'LB',
                    'length' => 8, 'width' => 6, 'height' => 4, 'dimension_units' => 'IN',
                    'customs_value' => 50.00, 'delivery_confirmation' => 'NO_SIGNATURE_REQUIRED',
                ],
                'items' => [[
                    'qty' => 1, 'customs_value' => 50.00, 'price' => 50.00,
                    'name' => $product2->getName(), 'weight' => 3.0,
                    'product_id' => $product2->getId(), 'order_item_id' => $orderItemsArray[1]->getId(),
                ]],
            ],
        ];
    }

    /**
     * Test creating shipment with multiple packages.
     *
     * @return void
     */
    #[
        ConfigFixture('carriers/fedex/active', '1', 'store', 'default'),
        ConfigFixture('carriers/fedex/api_key', 'test_api_key', 'store', 'default'),
        ConfigFixture('carriers/fedex/secret_key', 'test_secret_key', 'store', 'default'),
        ConfigFixture('carriers/fedex/account', 'test_account', 'store', 'default'),
        ConfigFixture('carriers/fedex/meter_number', 'test_meter', 'store', 'default'),
        ConfigFixture('carriers/fedex/sandbox_mode', '1', 'store', 'default'),
        ConfigFixture('carriers/fedex/allowed_methods', 'FEDEX_GROUND,FEDEX_2_DAY', 'store', 'default'),
        ConfigFixture('shipping/origin/country_id', 'US'),
        ConfigFixture('shipping/origin/region_id', '12'),
        ConfigFixture('shipping/origin/postcode', '90001'),
        ConfigFixture('shipping/origin/city', 'Los Angeles'),
        ConfigFixture('shipping/origin/street_line1', '123 Test Street'),
        ConfigFixture('general/store_information/name', 'Test Store'),
        ConfigFixture('general/store_information/phone', '5551234567'),
        DataFixture(ProductFixture::class, ['sku' => 'prod-1', 'price' => 50.00, 'weight' => 2.5], 'product1'),
        DataFixture(ProductFixture::class, ['sku' => 'prod-2', 'price' => 50.00, 'weight' => 3.0], 'product2'),
        DataFixture(Customer::class, as: 'customer'),
        DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product1.id$', 'qty' => 1]),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product2.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    ]
    public function testCreateShipmentWithMultiplePackages(): void
    {
        $order = $this->placeOrderFromCart($this->fixtures->get('cart'));
        $this->assertContains($order->getState(), [Order::STATE_NEW, Order::STATE_PROCESSING]);

        $shipment = $this->createShipmentForOrder($order);
        $this->assertInstanceOf(ShipmentInterface::class, $shipment);

        $orderItemsArray = array_values(iterator_to_array($order->getItems()));
        $packages = $this->buildMultiPackageData(
            $orderItemsArray,
            $this->fixtures->get('product1'),
            $this->fixtures->get('product2')
        );
        $shipment->setPackages($packages);
        $savedShipment = $this->shipmentRepository->save($shipment);

        $this->assertNotNull($savedShipment->getEntityId());
        $this->assertCount(2, $savedShipment->getPackages());
        $this->assertPackageData($savedShipment->getPackages());
    }

    /**
     * Assert package data is correct.
     *
     * @param array $savedPackages
     * @return void
     */
    private function assertPackageData(array $savedPackages): void
    {
        $this->assertEquals(5.0, $savedPackages[1]['params']['weight']);
        $this->assertEquals(10, $savedPackages[1]['params']['length']);
        $this->assertEquals(8, $savedPackages[1]['params']['width']);
        $this->assertEquals(6, $savedPackages[1]['params']['height']);
        $this->assertEquals(100.00, $savedPackages[1]['params']['customs_value']);
        $this->assertEquals('SIGNATURE', $savedPackages[1]['params']['delivery_confirmation']);
        $this->assertEquals(3.0, $savedPackages[2]['params']['weight']);
        $this->assertEquals(8, $savedPackages[2]['params']['length']);
        $this->assertEquals('NO_SIGNATURE_REQUIRED', $savedPackages[2]['params']['delivery_confirmation']);
    }

    /**
     * Test adding tracking information to shipment.
     *
     *  Covers Steps 6-7: Create shipping label and verify tracking information
     *  (Tracking Number, Carrier, Status, Service Type, Weight)
     *
     * @return void
     */
    #[
        ConfigFixture('carriers/fedex/active', '1', 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'track-product', 'price' => 100.00], 'product'),
        DataFixture(Customer::class, as: 'customer'),
        DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    ]
    public function testAddTrackingInformationToShipment(): void
    {
        $order = $this->placeOrderFromCart($this->fixtures->get('cart'));
        $shipment = $this->createShipmentForOrder($order);

        // Add tracking information (simulating shipping label creation response)
        // Tracking info includes: Tracking Number, Carrier, Service Type
        $track1 = $this->objectManager->create(ShipmentTrackInterface::class);
        $track1->setNumber('794644790132')
            ->setTitle('FedEx Ground')
            ->setCarrierCode('fedex')
            ->setDescription('Package 1 - Service Type: FEDEX_GROUND');

        $track2 = $this->objectManager->create(ShipmentTrackInterface::class);
        $track2->setNumber('794644790133')
            ->setTitle('FedEx Ground')
            ->setCarrierCode('fedex')
            ->setDescription('Package 2 - Service Type: FEDEX_GROUND');

        $shipment->addTrack($track1);
        $shipment->addTrack($track2);

        $this->shipmentRepository->save($shipment);

        // Verify tracking information is retrievable
        $savedShipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $tracks = $savedShipment->getTracks();

        $this->assertCount(2, $tracks);

        // Verify tracking data for both packages
        $trackNumbers = [];
        foreach ($tracks as $track) {
            $trackNumbers[] = $track->getTrackNumber();
            // Verify Carrier
            $this->assertEquals('fedex', $track->getCarrierCode());
            // Verify Service Type (in title)
            $this->assertEquals('FedEx Ground', $track->getTitle());
        }

        // Verify Tracking Numbers
        $this->assertContains('794644790132', $trackNumbers);
        $this->assertContains('794644790133', $trackNumbers);
    }

    /**
     * Test shipment packages can be retrieved after save.
     *
     *  Verify packages are stored and retrievable (Show Packages)
     *
     * @return void
     */
    #[
        ConfigFixture('carriers/fedex/active', '1', 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'pkg-product', 'price' => 75.00], 'product'),
        DataFixture(Customer::class, as: 'customer'),
        DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    ]
    public function testShipmentPackagesArePersisted(): void
    {
        $order = $this->placeOrderFromCart($this->fixtures->get('cart'));
        $shipment = $this->createShipmentForOrder($order);

        // Create 2 packages with complete data
        $packages = [
            1 => [
                'params' => [
                    'container' => 'YOUR_PACKAGING',
                    'weight' => 2.5,
                    'weight_units' => 'LB',
                    'length' => 12,
                    'width' => 10,
                    'height' => 8,
                    'dimension_units' => 'IN',
                ],
                'items' => [],
            ],
            2 => [
                'params' => [
                    'container' => 'YOUR_PACKAGING',
                    'weight' => 1.5,
                    'weight_units' => 'LB',
                    'length' => 6,
                    'width' => 4,
                    'height' => 2,
                    'dimension_units' => 'IN',
                ],
                'items' => [],
            ],
        ];

        $shipment->setPackages($packages);
        $this->shipmentRepository->save($shipment);

        // Reload shipment from database
        $reloadedShipment = $this->shipmentRepository->get((int)$shipment->getEntityId());

        // Verify 2 packages are displayed
        $this->assertCount(2, $reloadedShipment->getPackages());

        // Verify package data integrity (Weight, Dimensions)
        $reloadedPackages = $reloadedShipment->getPackages();
        $this->assertEquals(2.5, $reloadedPackages[1]['params']['weight']);
        $this->assertEquals(12, $reloadedPackages[1]['params']['length']);
        $this->assertEquals(10, $reloadedPackages[1]['params']['width']);
        $this->assertEquals(8, $reloadedPackages[1]['params']['height']);

        $this->assertEquals(1.5, $reloadedPackages[2]['params']['weight']);
        $this->assertEquals(6, $reloadedPackages[2]['params']['length']);
    }

    /**
     * Test shipment is associated with correct order for storefront access.
     *
     *  Verify shipment can be accessed from order (storefront scenario)
     *  - Log in to Storefront
     *  - Go to My Account > My Orders
     *  - Open Order Shipments tab
     *  - Click tracking number link
     *
     * @return void
     */
    #[
        ConfigFixture('carriers/fedex/active', '1', 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'sf-product', 'price' => 120.00], 'product'),
        DataFixture(Customer::class, as: 'customer'),
        DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    ]
    public function testShipmentAccessibleFromOrder(): void
    {
        $customer = $this->fixtures->get('customer');
        $order = $this->placeOrderFromCart($this->fixtures->get('cart'));
        $this->assertEquals($customer->getId(), $order->getCustomerId());

        $shipment = $this->createShipmentForOrder($order);
        $track = $this->objectManager->create(ShipmentTrackInterface::class);
        $track->setNumber('794644790134')
            ->setTitle('FedEx 2Day')
            ->setCarrierCode('fedex');

        $shipment->addTrack($track);
        $this->shipmentRepository->save($shipment);

        // Reload order (simulating storefront order view)
        $reloadedOrder = $this->orderRepository->get($order->getEntityId());

        // Verify shipment is accessible from order (Order Shipments tab)
        $shipmentCollection = $reloadedOrder->getShipmentsCollection();
        $this->assertCount(1, $shipmentCollection);
        $orderShipment = $shipmentCollection->getFirstItem();
        $this->assertEquals($shipment->getEntityId(), $orderShipment->getEntityId());

        // Verify tracking number is accessible (click tracking number link)
        $tracks = $orderShipment->getTracks();
        $this->assertCount(1, $tracks);

        // Get first track from collection (collection may not use numeric keys)
        $trackData = null;
        foreach ($tracks as $track) {
            $trackData = $track;
            break;
        }
        $this->assertNotNull($trackData, 'Track should exist');
        // Verify: Tracking Number
        $this->assertEquals('794644790134', $trackData->getTrackNumber());
        // Verify: Carrier
        $this->assertEquals('fedex', $trackData->getCarrierCode());
        // Verify: Service Type (in title)
        $this->assertEquals('FedEx 2Day', $trackData->getTitle());
    }

    /**
     * Test FedEx carrier is properly configured and active.
     *
     * @return void
     */
    #[
        ConfigFixture('carriers/fedex/active', '1', 'store', 'default'),
        ConfigFixture('carriers/fedex/title', 'Federal Express', 'store', 'default'),
        ConfigFixture('carriers/fedex/allowed_methods', 'FEDEX_GROUND,FEDEX_2_DAY', 'store', 'default'),
    ]
    public function testFedExCarrierConfiguration(): void
    {
        $carrierFactory = $this->objectManager->get(CarrierFactory::class);
        $fedexCarrier = $carrierFactory->create('fedex');

        $this->assertNotFalse($fedexCarrier, 'FedEx carrier should be created');
        $this->assertEquals('fedex', $fedexCarrier->getCarrierCode());

        $allowedMethods = $fedexCarrier->getAllowedMethods();
        $this->assertArrayHasKey('FEDEX_GROUND', $allowedMethods);
        $this->assertArrayHasKey('FEDEX_2_DAY', $allowedMethods);
    }

    /**
     * Test package weight and dimensions validation with all required fields.
     *
     *  Specify data for Packages
     *  (Type, Customs Value, Total Weight, Length, Width, Height, Signature Confirmation)
     *
     * @return void
     */
    #[
        ConfigFixture('carriers/fedex/active', '1', 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'val-product', 'price' => 200.00], 'product'),
        DataFixture(Customer::class, as: 'customer'),
        DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    ]
    public function testPackageDataValidation(): void
    {
        $product = $this->fixtures->get('product');
        $order = $this->placeOrderFromCart($this->fixtures->get('cart'));
        $shipment = $this->createShipmentForOrder($order);
        $orderItemId = array_values(iterator_to_array($order->getItems()))[0]->getId();

        // Test with complete package data as per Step 6 requirements
        $packages = [
            1 => [
                'params' => [
                    // Type
                    'container' => 'YOUR_PACKAGING',
                    'customs_value' => 200.00,
                    'weight' => 10.0,
                    'weight_units' => 'LB',
                    'length' => 20,
                    'width' => 15,
                    'height' => 10,
                    'dimension_units' => 'IN',
                    'delivery_confirmation' => 'SIGNATURE',
                ],
                'items' => [
                    [
                        'qty' => 2,
                        'customs_value' => 100.00,
                        'price' => 100.00,
                        'name' => $product->getName(),
                        'weight' => 5.0,
                        'product_id' => $product->getId(),
                        'order_item_id' => $orderItemId,
                    ],
                ],
            ],
        ];

        $shipment->setPackages($packages);
        $savedShipment = $this->shipmentRepository->save($shipment);

        // Verify all package data is persisted correctly
        $savedPackages = $savedShipment->getPackages();
        $this->assertEquals('YOUR_PACKAGING', $savedPackages[1]['params']['container']);
        $this->assertEquals(200.00, $savedPackages[1]['params']['customs_value']);
        $this->assertEquals(10.0, $savedPackages[1]['params']['weight']);
        $this->assertEquals(20, $savedPackages[1]['params']['length']);
        $this->assertEquals(15, $savedPackages[1]['params']['width']);
        $this->assertEquals(10, $savedPackages[1]['params']['height']);
        $this->assertEquals('SIGNATURE', $savedPackages[1]['params']['delivery_confirmation']);
    }
}
