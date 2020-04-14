# CreateOrder

 - [Main Functionalities](#markdown-header-main-functionalities)
 - [Installation](#markdown-header-installation)
 - [Configuration](#markdown-header-configuration)
 - [Specifications](#markdown-header-specifications)
 - [Attributes](#markdown-header-attributes)

## Main Functionalities
Create order through API project

## Installation
\* = in production please use the `--keep-generated` option

### Type 1: Zip file

 - Unzip the zip file in `app/code/PSSoftware`
 - Enable the module by running `php bin/magento module:enable PSSoftware_CreateOrder`
 - Apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`

### Type 2: Composer

 - Install the module composer by running `composer require pssoftware/create-order`
 - enable the module by running `php bin/magento module:enable PSSoftware_CreateOrder`
 - apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`

## Configuration

## Specifications

 - API Endpoint
	- POST - PSSoftware\CreateOrder\Api\PurchaseManagementInterface > PSSoftware\CreateOrder\Model\PurchaseManagement

## Attributes



