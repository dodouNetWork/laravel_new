<?php

namespace App\Models\Pay;

use App\Models\BaseModel;
use App\Models\BaseModelTrait;

class MultiBill extends BaseModel
{
    use BaseModelTrait;

    // 支付方式
    const PAY_WAY = [
        1 => '微信',
        2 => '支付宝',
    ];

    const PAY_WAY_ALIAS = [
        1 => 'wechat',
        2 => 'alipay',
    ];

    const STATUS = [
        1 => '未支付',
        2 => '付款成功',
        3 => '付款失败',
        4 => '付款取消',
        5 => '退款中',
        6 => '退款成功',
        7 => '退款失败',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (MultiBill $bill) {
            if (!$bill->pay_no) {
                $bill->pay_no = self::getNewNumber(self::class);
            }
            if (!$bill->status) {
                $bill->status = 1;
            }
        });
    }

    public function billable()
    {
        return $this->morphTo();
    }

    // 支付方式名称 pay_way_name
    public function getPayWayNameAttribute()
    {
        return self::PAY_WAY[$this->pay_way] ?? '';
    }

    /**
     * 支付回调通知处理 - 异步
     *
     * @param $data
     * @param int $payWay
     * @return bool
     */
    public static function handleNotify($data, int $payWay)
    {
        $bill = self::where('pay_no', $data->pay_no)->where('pay_way', $payWay)->first();
        if (!$bill) {
            pl('找不到支付订单信息：' . $data->pay_no, self::PAY_WAY_ALIAS[$payWay] . '-notify-err', 'pay');
            return true;
        }

        if ($bill->status != 1) {
            // 本地订单状态已取消，安排退款
            if ($bill->status == 4) {
                // todo
                return true;
            }
            pl('订单状态非未支付：' . $data->pay_no . '，订单状态：' . $bill->status_name, self::PAY_WAY_ALIAS[$payWay] . '-notify-comment', 'pay');
            return true;
        }
        if ($bill->amount != $data->amount) {
            pl('订单支付金额不一致：' . $data->pay_no . '，订单金额：' . $bill->amount . '，回调金额：' . $data->amount, self::PAY_WAY_ALIAS[$payWay] . '-notify-comment', 'pay');
            return false;
        }

        $bill->fill([
            'pay_service_no' => $data->pay_service_no,
            'pay_at' => now(),
            'status' => 2,
        ]);

        $bill->save();

        $bill->billable->handlePay($bill);

        return true;
    }

    /**
     * 支付回调通知处理 - 同步
     *
     * @param $payNo
     * @return mixed
     */
    public static function handleReturn($payNo)
    {
        $bill = MultiBill::where('pay_no', $payNo)->first();
        if (!$bill) {
            abort('404', '订单不翼而飞了 :(');
        }
        return $bill->billable->payResult();
    }
}
