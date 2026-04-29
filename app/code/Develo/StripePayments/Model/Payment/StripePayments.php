<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

class StripePayments extends AbstractMethod
{
    protected $_code = 'stripe_payments';
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canRefund = false;
}
