<?php
/**
 * @author   Dejan Beljic <beljic@gmail.com>
 * @package  PSSoftware|CreateOrder
 */
namespace PSSoftware\CreateOrder\Api;

/**
 * Interface for creating order
 *
 * @api
 */
interface PurchaseManagementInterface
{
    /**
     * POST for purchase api
     *
     * @param mixed $customerData
     * @param mixed $productData
     * @return string
     */
    public function postPurchase($customerData, $productData);
}
