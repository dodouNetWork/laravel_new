<?php

namespace App\Http\Controllers\Service\Pay;

use App\Models\Pay\MultiBill;
use Exception;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Facades\Agent;
use Yansongda\LaravelPay\Facades\Pay;
use Yansongda\Pay\Log;

class AlipayService
{
    /**
     * 发起支付请求
     *
     * @param array $data => ['no' => '订单号', 'amount' => '金额', 'name' => '订单名称']
     * @param string $drive
     * @param array $extData
     * @param string $custom
     * @return mixed
     */
    public function pay($data, $drive = '', $extData = [], $custom = '')
    {
        $subject = strlen($data['name']) > 18 ? $data['name'] : config('app.name') . ' - ' . $data['name'];
        $order = [
            'out_trade_no' => $data['no'],
            'total_amount' => $data['amount'],
            'subject' => $subject,
        ];
        if ($custom) {
            $order['passback_params'] = urlencode($custom);
        }
        if (count($extData) > 0) {
            foreach ($extData as $key => $value) {
                $order[$key] = $value;
            }
        }

        if (!$drive) {
            if (Agent::isMobile()) {
                $drive = 'wap';
            } else {
                $drive = 'web';
            }
        }

        return Pay::alipay()->$drive($order);
    }

    /**
     * 支付回调异步通知
     *
     * @return string
     */
    public function notify()
    {
        $alipay = Pay::alipay();
        DB::beginTransaction();
        try {
            $data = $alipay->verify(); // 是的，验签就这么简单！
            if ($data->app_id == config('pay.alipay.app_id') && in_array($data->trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
                $data->pay_no = $data->out_trade_no; // 统一变量名，支付商户订单号
                $data->pay_service_no = $data->trade_no; // 统一变量名，支付商订单号
                $data->amount = $data->total_amount; // 统一变量名，订单金额
                $result = MultiBill::handleNotify($data, 2);
                if ($result) {
                    DB::commit();
                    return $alipay->success();
                } else {
                    DB::rollBack();
                    return '';
                }
            }
            Log::debug('Alipay notify verify fail', $data->all());
            DB::rollBack();
            return '';
        } catch (Exception $e) {
            pl('支付宝回调失败：' . $e->getMessage(), 'notify', 'alipay');
            DB::rollBack();
            return '';
        }
    }

    /**
     * 支付回调同步通知-支付宝
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function return()
    {
        $data = Pay::alipay()->verify(); // 是的，验签就这么简单！
        // 订单号：$data->out_trade_no
        // 支付宝交易号：$data->trade_no
        // 订单总金额：$data->total_amount
        if ($data->app_id == config('pay.alipay.app_id')) {
            dd('支付成功' . var_export($data->all(), true));
        }
        return redirect('/');
    }
}