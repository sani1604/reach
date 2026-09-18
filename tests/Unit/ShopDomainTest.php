<?php

namespace Tests\Unit;

use App\Services\ShopDomain;
use PHPUnit\Framework\TestCase;

class ShopDomainTest extends TestCase
{
    public function test_normalizes_valid_shop_domains(): void
    {
        $this->assertSame('acme.myshopify.com', ShopDomain::normalize('acme.myshopify.com'));
        $this->assertSame('acme.myshopify.com', ShopDomain::normalize('ACME.myshopify.com'));
        $this->assertSame('acme.myshopify.com', ShopDomain::normalize('https://acme.myshopify.com/admin'));
        $this->assertSame('acme.myshopify.com', ShopDomain::normalize('acme'));
    }

    public function test_rejects_non_shopify_hosts(): void
    {
        $this->assertNull(ShopDomain::normalize('evil.com'));
        $this->assertNull(ShopDomain::normalize('acme.myshopify.com.evil.com'));
        $this->assertNull(ShopDomain::normalize('127.0.0.1'));
        $this->assertNull(ShopDomain::normalize('localhost'));
        $this->assertNull(ShopDomain::normalize(''));
        $this->assertNull(ShopDomain::normalize(null));
        $this->assertNull(ShopDomain::normalize('not a domain!!'));
    }

    public function test_safe_admin_host_param(): void
    {
        $good = base64_encode('admin.shopify.com/store/acme');
        $this->assertSame('admin.shopify.com/store/acme', ShopDomain::safeAdminHostParam($good));

        $shop = base64_encode('acme.myshopify.com/admin');
        $this->assertSame('acme.myshopify.com/admin', ShopDomain::safeAdminHostParam($shop));

        $evil = base64_encode('evil.com/phish');
        $this->assertNull(ShopDomain::safeAdminHostParam($evil));

        $ssrf = base64_encode('169.254.169.254/latest/meta-data');
        $this->assertNull(ShopDomain::safeAdminHostParam($ssrf));
    }

    public function test_safe_app_path(): void
    {
        $this->assertSame('/dashboard', ShopDomain::safeAppPath('/dashboard'));
        $this->assertSame('/settings', ShopDomain::safeAppPath('settings'));
        $this->assertSame('/dashboard', ShopDomain::safeAppPath('https://evil.com'));
        $this->assertSame('/dashboard', ShopDomain::safeAppPath('//evil.com'));
        $this->assertSame('/dashboard', ShopDomain::safeAppPath('/../etc/passwd'));
        $this->assertSame('/dashboard', ShopDomain::safeAppPath(null));
    }
}
