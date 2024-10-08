<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Paypal\Test\Unit\Controller\Express;

use Magento\CheckoutAgreements\Model\AgreementsValidator;
use Magento\Framework\DataObject;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Paypal\Model\Api\ProcessableException;
use Magento\Paypal\Test\Unit\Controller\ExpressTestCase;

class PlaceOrderTest extends ExpressTestCase
{
    protected $name = 'PlaceOrder';

    /**
     * @param bool $isGeneral
     * @dataProvider trueFalseDataProvider
     */
    public function testExecuteNonProcessableException($isGeneral)
    {
        if (!$isGeneral) {
            $this->request->expects($this->once())
                ->method('getPost')
                ->with('agreement', [])
                ->willReturn([]);
        }
        $this->_expectRedirect();
        $this->model->execute();
    }

    /**
     * @param string $path
     */
    protected function _expectRedirect($path = '*/*/review')
    {
        $this->redirect->expects($this->once())
            ->method('redirect')
            ->with($this->anything(), $path, []);
    }

    /**
     * @return array
     */
    public static function trueFalseDataProvider()
    {
        return [[true], [false]];
    }

    /**
     * @param int $code
     * @param null|string $paymentAction
     * @dataProvider executeProcessableExceptionDataProvider
     */
    public function testExecuteProcessableException($code, $paymentAction = null)
    {
        $this->request->expects($this->once())
            ->method('getPost')
            ->with('agreement', [])
            ->willReturn([]);
        $oldCallback = &$this->objectManagerCallback;
        $this->objectManagerCallback = function ($className) use ($code, $oldCallback) {
            $instance = call_user_func($oldCallback, $className);
            if ($className == AgreementsValidator::class) {
                $exception = $this->createPartialMock(
                    ProcessableException::class,
                    ['getUserMessage']
                );
                $exception->expects($this->any())
                    ->method('getUserMessage')
                    ->willReturn('User Message');
                $instance->expects($this->once())
                    ->method('isValid')
                    ->will($this->throwException($exception));
            }
            return $instance;
        };
        if (isset($paymentAction)) {
            $this->config->expects($this->once())
                ->method('getPaymentAction')
                ->willReturn($paymentAction);
        }
        $this->_expectErrorCodes($code, $paymentAction);
        $this->model->execute();
    }

    /**
     * @return array
     */
    public static function executeProcessableExceptionDataProvider()
    {
        return [
            [ProcessableException::API_MAX_PAYMENT_ATTEMPTS_EXCEEDED],
            [ProcessableException::API_TRANSACTION_EXPIRED],
            [ProcessableException::API_DO_EXPRESS_CHECKOUT_FAIL],
            [
                ProcessableException::API_UNABLE_TRANSACTION_COMPLETE,
                AbstractMethod::ACTION_ORDER
            ],
            [ProcessableException::API_UNABLE_TRANSACTION_COMPLETE, 'other'],
            [999999],
        ];
    }

    /**
     * @param int $code
     * @param null|string $paymentAction
     */
    protected function _expectErrorCodes($code, $paymentAction)
    {
        $redirectUrl = 'redirect by test';
        if (in_array(
            $code,
            [
                ProcessableException::API_MAX_PAYMENT_ATTEMPTS_EXCEEDED,
                ProcessableException::API_TRANSACTION_EXPIRED,
            ]
        )
        ) {
            $payment = new DataObject(['checkout_redirect_url' => $redirectUrl]);
            $this->quote->expects($this->once())
                ->method('getPayment')
                ->willReturn($payment);
        }
        if ($code == ProcessableException::API_UNABLE_TRANSACTION_COMPLETE
            && $paymentAction == AbstractMethod::ACTION_ORDER
        ) {
            $this->config->expects($this->once())
                ->method('getExpressCheckoutOrderUrl')
                ->willReturn($redirectUrl);
        }
        if ($code == ProcessableException::API_DO_EXPRESS_CHECKOUT_FAIL
            || $code == ProcessableException::API_UNABLE_TRANSACTION_COMPLETE
            && $paymentAction != AbstractMethod::ACTION_ORDER
        ) {
            $this->config->expects($this->once())
                ->method('getExpressCheckoutStartUrl')
                ->willReturn($redirectUrl);
            $this->request->expects($this->once())
                ->method('getParam')
                ->with('token');
        }
        if (in_array(
            $code,
            [
                ProcessableException::API_MAX_PAYMENT_ATTEMPTS_EXCEEDED,
                ProcessableException::API_TRANSACTION_EXPIRED,
                ProcessableException::API_DO_EXPRESS_CHECKOUT_FAIL,
                ProcessableException::API_UNABLE_TRANSACTION_COMPLETE,
            ]
        )
        ) {
            $this->response->expects($this->once())
                ->method('setRedirect')
                ->with($redirectUrl);
        } else {
            $this->messageManager->expects($this->once())
                ->method('addError')
                ->with('User Message');
            $this->_expectRedirect('checkout/cart');
        }
    }
}
