<?php
/**
 * @author   Dejan Beljic <beljic@gmail.com>
 * @package  PSSoftware|CreateOrder
 */

namespace PSSoftware\CreateOrder\Model;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Data\Customer;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\Json\Helper\Data;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Sales\Model\Service\OrderService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PSSoftware\CreateOrder\Api\PurchaseManagementInterface;

/**
 * Create order management model.
 *
 */
class PurchaseManagement implements PurchaseManagementInterface
{
    /*
     * Messages
     */
    const INIT_CUSTOMER_INTERNAL_ERROR = 'Internal error, unable to init customer, please, try again.';
    const DEFAULT_BILLING_ADDRESS_INTERNAL_ERROR = 'Default billing address is not set.';
    const DEFAULT_SHIPPING_ADDRESS_INTERNAL_ERROR = 'Default shipping address is not set.';

    /*
     * Default payment/shipping
     */
    const PAYMENT_METHOD = 'checkmo';
    const SHIPPING_METHOD_RATE = 'freeshipping_freeshipping';
    const SHIPPING_METHOD = 'flatrate_flatrate';

    /**
     * @var Data $jsonHelper
     */
    protected $jsonHelper;

    /**
     * @var CustomerInterfaceFactory
     */
    protected $customerFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var AddressInterfaceFactory
     */
    protected $addressFactory;

    /**
     * @var AddressRepositoryInterface
     */
    protected $addressRepository;

    /**
     * @var ProductRepositoryInterface $_productRepository
     */
    private $productRepository;

    /**
     * @var ProductFactory $_productFactory
     */
    private $productFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var CartManagementInterface
     */
    protected $quoteManagement;

    /**
     * @var Rate
     */
    protected $shippingRate;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var $logger LoggerInterface
     */
    private $logger;

    public function __construct(
        Context $context,
        CustomerInterfaceFactory $customerFactory,
        AddressInterfaceFactory $addressFactory,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        ProductRepositoryInterface $productRepository,
        ProductFactory $productFactory,
        StoreManagerInterface $storeManager,
        OrderService $orderService,
        CartRepositoryInterface $quoteRepository,
        CartManagementInterface $quoteManagement,
        Rate $shippingRate,
        Data $jsonHelper,
        LoggerInterface $logger
    )
    {
        $this->customerFactory = $customerFactory;
        $this->storeManager = $storeManager;
        $this->messageManager = $context->getMessageManager();
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->orderService = $orderService;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->shippingRate = $shippingRate;
        $this->jsonHelper = $jsonHelper;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function postPurchase($customerData, $productData)
    {
        $response = $this->purchase($customerData, $productData);

        return $this->jsonHelper->jsonEncode($response);
    }

    /**
     * Create Order
     *
     * @param $customerData
     * @param $itemsData
     * @return array
     */
    public function purchase($customerData, $itemsData)
    {
        $store = $this->storeManager->getStore();
        $websiteId = $store->getWebsiteId();
        $response = [];

        //init the customer
        try {
            $customer = $this->initCustomer($customerData, $store, $websiteId);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            return [
                'errors' => true,
                'message' => self::INIT_CUSTOMER_INTERNAL_ERROR
            ];
        }

        try {
            //init the quote
            $quoteId = $this->quoteManagement->createEmptyCart();
            /**
             * @var Quote $quote
             */
            $quote = $this->quoteRepository->get($quoteId);
            $quote->setStore($store);

            $quote->setCurrency();
            $quote->assignCustomer($customer);

            //add items to quote
            foreach ($itemsData as $productId => $qty) {
                if ($qty > 0) {
                    $product = $this->productRepository->getById($productId);

                    $quote->addProduct(
                        $product,
                        intval($qty)
                    );
                }
            }

            $quote->getBillingAddress()->addData([$this->getBillingAddress($customer)]);
            $quote->getShippingAddress()->addData([$this->getShippingAddress($customer)]);

            // Collect Rates and Set Shipping & Payment Method
            $this->shippingRate
                ->setCode(self::SHIPPING_METHOD_RATE)
                ->getPrice();

            $shippingAddress = $quote->getShippingAddress();

            $shippingAddress->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod(self::SHIPPING_METHOD); //shipping method

            $quote->getShippingAddress()->addShippingRate($this->shippingRate);
            $quote->setPaymentMethod(self::PAYMENT_METHOD); //payment method

            $quote->setInventoryProcessed(false);

            // Set sales order payment
            $quote->getPayment()->importData(['method' => self::PAYMENT_METHOD]);

            // Collect total and save
            $quote->collectTotals();

            // Submit the quote and create the order
            $order = $this->quoteManagement->submit($quote);

            $response = [
                'errors' => false,
                'message' => __('Order ' . $order->getId() . ' has been created.')
            ];

        } catch (LocalizedException $le) {
            $this->logger->error($le->getMessage());
            $response = [
                'errors' => true,
                'message' => $le->getRawMessage()
            ];
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $response = [
                'errors' => true,
                'message' => $e->getMessage()
            ];
        }

        return $response;
    }

    /**
     * Create/load customer
     *
     * @param $customerData
     * @param $websiteId
     * @param $store
     * @return Customer
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws InputException
     * @throws InputMismatchException
     */
    public function initCustomer($customerData, $websiteId = 0, $store)
    {
        if (isset($customerData['customer_id']) && $customerData['customer_id']) {
            /**
             * @var Customer $customer
             */
            $customer = $this->customerRepository->getById($customerData['customer_id']);
        } else {
            $customer = $this->customerFactory->create();

            $customer->setWebsiteId($websiteId)
                ->setStore($store)
                ->setFirstname($customerData['shipping_address']['firstname'])
                ->setLastname($customerData['shipping_address']['lastname'])
                ->setEmail($customerData['email'])
                ->setPassword($customerData['email']);

            $this->customerRepository->save($customer);
        }

        return $customer;
    }

    /**
     * @param Customer $customer
     */

    /**
     * Extract billing address
     *
     * @param $customer
     * @return AddressInterface
     * @throws LocalizedException
     * @throws Exception
     */
    public function getBillingAddress($customer)
    {
        if ($customer->getDefaultBilling()) {
            return $this->addressRepository->getById($customer->getDefaultBilling());
        } else {
            throw new Exception(
                self::DEFAULT_BILLING_ADDRESS_INTERNAL_ERROR
            );
        }
    }

    /**
     * Extract shipping address
     *
     * @param $customer
     * @return AddressInterface
     * @throws LocalizedException
     * @throws Exception
     */
    public function getShippingAddress($customer)
    {
        if ($customer->getDefaultShipping()) {
            return $this->addressRepository->getById($customer->getDefaultShipping());
        } else {
            throw new Exception(
                self::DEFAULT_SHIPPING_ADDRESS_INTERNAL_ERROR
            );
        }
    }
}
