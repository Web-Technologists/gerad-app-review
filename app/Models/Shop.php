<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_domain',
        'shop_name',
        'custom_domain',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /**
     * Get the products owned by this shop.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Shop $shop) {
            if (empty($shop->scopes)) {
                $shop->scopes = ['read_products', 'write_products'];
            }
            if (empty($shop->status)) {
                $shop->status = 'active';
            }
        });
    }

    /**
     * Get the primary licensor attribute for the shop.
     */
    public function getPrimaryLicensorAttribute(): string
    {
        $name = strtolower($this->shop_name ?? '');
        $domain = strtolower($this->shop_domain ?? '');

        if (str_contains($name, 'dphie')) {
            return 'DELTA PHI EPSILON';
        }
        if (str_contains($name, 'dz') || str_contains($name, 'delta zeta')) {
            return 'DELTA ZETA';
        }
        if (str_contains($name, 'ast') || str_contains($name, 'alpha sigma tau')) {
            return 'ALPHA SIGMA TAU';
        }
        if (str_contains($name, 'penguin') || str_contains($name, 'theta phi alpha')) {
            return 'THETA PHI ALPHA';
        }
        if (str_contains($name, 'phide') || str_contains($name, 'phi delta epsilon')) {
            return 'PHI DELTA EPSILON';
        }
        if (str_contains($name, 'hannah') || str_contains($name, 'delta gamma')) {
            return 'DELTA GAMMA';
        }
        if (str_contains($name, 'social life')) {
            return 'VARIOUS';
        }
        if (str_contains($name, 'delt') || str_contains($name, 'delta tau delta')) {
            return 'DELTA TAU DELTA';
        }
        if (str_contains($name, 'tri sigma') || str_contains($name, 'sigma sigma sigma')) {
            return 'SIGMA SIGMA SIGMA';
        }
        if (str_contains($name, 'pi kapp') || str_contains($name, 'pi kappa phi')) {
            return 'PI KAPPA PHI';
        }
        if (str_contains($name, 'kappa')) {
            return 'KAPPA KAPPA GAMMA';
        }
        if (str_contains($name, 'ncl') || str_contains($name, 'national charity league')) {
            return 'NATIONAL CHARITY LEAGUE';
        }
        if (str_contains($name, 'sdt') || str_contains($name, 'sigma delta tau')) {
            return 'SIGMA DELTA TAU';
        }
        if (str_contains($name, 'akdphi') || str_contains($name, 'kappa delta phi')) {
            return 'alpha KAPPA DELTA PHI';
        }

        // Fallbacks based on domain keyword
        if (str_contains($domain, 'dphie')) {
            return 'DELTA PHI EPSILON';
        }
        if (str_contains($domain, 'dz') || str_contains($domain, 'deltazeta')) {
            return 'DELTA ZETA';
        }
        if (str_contains($domain, 'ast') || str_contains($domain, 'alphasigmatau')) {
            return 'ALPHA SIGMA TAU';
        }
        if (str_contains($domain, 'penguin') || str_contains($domain, 'thetaphialpha')) {
            return 'THETA PHI ALPHA';
        }
        if (str_contains($domain, 'phide') || str_contains($domain, 'phideltaepsilon')) {
            return 'PHI DELTA EPSILON';
        }
        if (str_contains($domain, 'hannah') || str_contains($domain, 'deltagamma')) {
            return 'DELTA GAMMA';
        }
        if (str_contains($domain, 'sociallife')) {
            return 'VARIOUS';
        }
        if (str_contains($domain, 'delt') || str_contains($domain, 'deltataudelta')) {
            return 'DELTA TAU DELTA';
        }
        if (str_contains($domain, 'trisigma') || str_contains($domain, 'sigmasigmasigma')) {
            return 'SIGMA SIGMA SIGMA';
        }
        if (str_contains($domain, 'pikapp') || str_contains($domain, 'pikappaphi')) {
            return 'PI KAPPA PHI';
        }
        if (str_contains($domain, 'kappa') || str_contains($domain, 'kappakappagamma')) {
            return 'KAPPA KAPPA GAMMA';
        }
        if (str_contains($domain, 'ncl') || str_contains($domain, 'nationalcharityleague')) {
            return 'NATIONAL CHARITY LEAGUE';
        }
        if (str_contains($domain, 'sdt') || str_contains($domain, 'sigmadeltatau')) {
            return 'SIGMA DELTA TAU';
        }
        if (str_contains($domain, 'akdphi')) {
            return 'alpha KAPPA DELTA PHI';
        }

        return '';
    }
}
