<?php

namespace Wtsergo\CopyProductsMagentoShopware\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Wtsergo\CopyProductsMagentoShopware\RefetchShopwareIds;

class CopyProductsMagentoShopware extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wtsergo:copy-products-magento-shopware'
        . ' {--magento-base-url=} {--magento-access-token=}'
        . ' {--shopware-base-url=} {--shopware-api-key=} {--shopware-api-secret=}  {--shopware-tax-id=} {--shopware-currency-id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy products from magento to shopware';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {

            $requiredOptNames = [
                'magento-base-url', 'magento-access-token',
                'shopware-base-url', 'shopware-api-key', 'shopware-api-secret',
                'shopware-tax-id', 'shopware-currency-id'
            ];
            foreach ($requiredOptNames as $optName) {
                if (!$this->option($optName)) {
                    throw new \Exception($optName.' is required');
                }
            }

            $products = $this->fetchMagentoProducts();
            $attributes = $this->fetchMagentoAttributes();

            $propertyGroups = $this->collectProductsPropertyGroups($products, $attributes);

            $childProductIdsMap = $parentProductIds = [];
            foreach ($products as $product) {
                if ($product['extension_attributes']['configurable_product_links']??false) {
                    foreach ($product['extension_attributes']['configurable_product_links'] as $childId) {
                        $propertyGroupNames = [];
                        foreach ($product['extension_attributes']['configurable_product_options'] as $cfgOption) {
                            $attribute = $this->findAttribute($attributes, (int)$cfgOption['attribute_id']);
                            $propertyGroupNames[] = $attribute['attribute_code'];
                        }

                        $childProductIdsMap[$childId] = [
                            'id' => $product['id'],
                            'sku' => $product['sku'],
                            'property_group_names' => $propertyGroupNames
                        ];
                    }
                    $parentProductIds[] = $product['id'];
                }
            }

            $childProducts = $parentProducts = $simpleProducts = [];
            foreach ($products as $product) {
                if (in_array($product['id'], $parentProductIds)) {
                    $parentProducts[] = $product;
                } else if (array_key_exists($product['id'], $childProductIdsMap)) {
                    $childProducts[] = $product;
                } else {
                    $simpleProducts[] = $product;
                }
            }

            $shopwareAuthData = $this->getShopwareAuthData();

            $shopwareClient = $this->createShopwareClient($shopwareAuthData['access_token']);

            foreach (array_merge($simpleProducts, $parentProducts) as $product) {
                $shopwareProduct = $this->fetchShopwareProduct($product['sku']);
                if ($shopwareProduct) {
                    $apiPath = 'api/product/'.$shopwareProduct['id'];
                    $apiMethod = 'patch';
                } else {
                    $apiPath = 'api/product';
                    $apiMethod = 'post';
                }

                $response = $shopwareClient->$apiMethod($apiPath, [
                    'json' => [
                        'name' => $product['name'],
                        'productNumber' => $product['sku'],
                        'stock' => 100,
                        'taxId' => $this->option('shopware-tax-id'),
                        'price' => [[
                            "currencyId" => $this->option('shopware-currency-id'),
                            "gross" => (float)$product['price'],
                            "net" => (float)$product['price'],
                            "linked" => false
                        ]]
                    ]
                ]);
                $this->assertResponseStatus(204, $response, 'shopware', $apiPath);
            }
            foreach ($childProducts as $product) {
                $shopwareProduct = $this->fetchShopwareProduct($product['sku']);
                $parentInfo = $childProductIdsMap[$product['id']];
                $shopwareParentProduct = $this->fetchShopwareProduct($parentInfo['sku']);

                $configuratorSettings = [];
                $propertyGroupNames = $parentInfo['property_group_names'];
                foreach ($propertyGroupNames as $groupName) {
                    $configuratorLine = [];
                    $configuratorLine['groupId'] = $propertyGroups[$groupName]['id'];
                    $attributeOption = null;
                    foreach ($product['custom_attributes'] as $customAttribute) {
                        if ($customAttribute['attribute_code'] === $groupName) {
                            $attribute = $this->findAttribute($attributes, (string)$customAttribute['attribute_code']);
                            $attributeOption = $this->findAttributeOption($attribute, $customAttribute['value']);
                            break;
                        }
                    }
                    if (!$attributeOption) {
                        throw new \Exception('Attribute option not found');
                    }
                    $propertyGroupOptionId = null;
                    foreach ($propertyGroups[$groupName]['values'] as $optionName => $option) {
                        if ($optionName == $attributeOption['label']) {
                            $propertyGroupOptionId = $option['id'];
                            break;
                        }
                    }
                    if (!$propertyGroupOptionId) {
                        throw new \Exception('Property group option id not found');
                    }
                    $configuratorLine['option']['id'] = $propertyGroupOptionId;
                    $configuratorSettings[] = $configuratorLine;
                }
                $apiPath = 'api/product/'.$shopwareParentProduct['id'];
                $shopwareClient->patch($apiPath, [
                    'json' => [
                        'configuratorSettings' => $configuratorSettings
                    ]
                ]);

                if ($shopwareProduct) {
                    $apiPath = 'api/product/'.$shopwareProduct['id'];
                    $apiMethod = 'patch';
                } else {
                    $apiPath = 'api/product';
                    $apiMethod = 'post';
                }

                $shopwareClient->$apiMethod($apiPath, [
                    'json' => [
                        "parentId" => $shopwareParentProduct['id'],
                        'name' => $product['name'],
                        'productNumber' => $product['sku'],
                        'stock' => 100,
                        'taxId' => $this->option('shopware-tax-id'),
                        'price' => [[
                            "currencyId" => $this->option('shopware-currency-id'),
                            "gross" => (float)$product['price'],
                            "net" => (float)$product['price'],
                            "linked" => false
                        ]],
                        'options' => array_map(fn($cl) => ['id' => $cl['option']['id']], $configuratorSettings)
                    ]
                ]);

            }
        } catch (\Throwable $throwable) {
            foreach ($this->history as $transaction) {
                $request = $transaction['request'];
                $response = $transaction['response'];
                echo "--- Raw Request ---\n";
                echo $request->getMethod() . ' ' . $request->getUri() . ' HTTP/' . $request->getProtocolVersion() . "\n";
                foreach ($request->getHeaders() as $name => $values) {
                    echo $name . ': ' . implode(', ', $values) . "\n";
                }
                // Rewind the stream before getting contents to ensure you get the full body
                $request->getBody()->rewind();
                echo "\n" . $request->getBody()->getContents() . "\n";
                $response->getBody()->rewind();
                echo "\n" . $response->getBody()->getContents() . "\n";
            }
            var_dump("$throwable");
        }

    }

    private HandlerStack $handlerStack;
    private function handlerStack(): HandlerStack
    {
        return $this->handlerStack ??= $this->createHandlerStack();
    }

    private array $history = [];
    private function createHandlerStack(): HandlerStack
    {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(Middleware::history($this->history));
        return $handlerStack;
    }

    private function collectProductsPropertyGroups(array $products, array $attributes): array
    {
        $propertyGroups = [];
        foreach ($products as $product) {
            if (($cfgOptions = $product['extension_attributes']['configurable_product_options'] ?? false)) {
                foreach ($cfgOptions as $cfgOption) {
                    $attributeId = $cfgOption['attribute_id'];
                    $attribute = $this->findAttribute($attributes, (int)$attributeId);
                    $attributeCode = $attribute['attribute_code'];
                    if (!isset($propertyGroups[$attributeCode])) {
                        $propertyGroups[$attributeCode] = ['name' => $attributeCode, 'values' => []];
                    }
                    foreach ($cfgOption['values'] as $cfgOptionValue) {
                        $optionId = $cfgOptionValue['value_index'];
                        $option = $this->findAttributeOption($attribute, $optionId);
                        $optionName = $option['label'];
                        $propertyGroups[$attributeCode]['values'][$optionName] = [
                            'name' => $option['label'],
                        ];
                    }
                }
            }
        }
        do {
            try {
                $propertyGroups = $this->attachShopwareIdsToPropertyGroups($propertyGroups);
                $refetch = false;
            } catch (RefetchShopwareIds) {
                $refetch = true;
            }
        } while ($refetch);
        return $this->attachShopwareIdsToPropertyGroups(
            $this->attachShopwareIdsToPropertyGroups($propertyGroups)
        );
    }

    private function fetchShopwareProduct(string $productNumber): ?array
    {
        $shopwareClient = $this->createShopwareClient($this->getShopwareAuthData()['access_token']);
        $apiPath = 'api/search/product';
        $response = $shopwareClient->post($apiPath, [
            'json' => [
                "filter" => [[
                    "type" => "equalsAny",
                    "field" => "productNumber",
                    "value" => [$productNumber]
                ]],
            ]
        ]);
        $this->assertResponseStatus(200, $response, 'shopware', $apiPath);
        $data = json_decode((string)$response->getBody(), true)['data'];
        return array_shift($data);
    }

    private function attachShopwareIdsToPropertyGroups($propertyGroups): array
    {
        $shopwareClient = $this->createShopwareClient($this->getShopwareAuthData()['access_token']);
        $apiPath = 'api/property-group';
        $response = $shopwareClient->get($apiPath);
        $this->assertResponseStatus(200, $response, 'shopware', $apiPath);
        $existingGroups = json_decode((string)$response->getBody(), true)['data'];
        $apiPath = 'api/property-group-option';
        $response = $shopwareClient->get($apiPath);
        $this->assertResponseStatus(200, $response, 'shopware', $apiPath);
        $existingOptions = json_decode((string)$response->getBody(), true)['data'];
        $resultGroups = [];
        foreach ($propertyGroups as $groupName => $propertyGroup) {
            $resultGroup = $propertyGroup;
            $groupId = $this->findGroupId($existingGroups, $groupName);
            if ($groupId) {
                $resultGroup['id'] = $groupId;
                $resultOptions = [];
                foreach ($resultGroup['values'] as $optionName => &$option) {
                    $resultOption = $option;
                    $optionId = $this->findOptionId($existingOptions, $optionName, $resultGroup['id']);
                    if (!$optionId) {
                        $apiPath = 'api/property-group-option';
                        $response = $shopwareClient->post($apiPath, [
                            'json' => [
                                "groupId" => "019a827a25d271c490a917a0972d16db",
                                "name" => $optionName,
                            ]
                        ]);
                        $this->assertResponseStatus(204, $response, 'shopware', $apiPath);
                        throw new RefetchShopwareIds();
                    } else {
                        $resultOption['id'] = $optionId;
                    }
                    $resultOptions[$optionName] = $resultOption;
                }
                unset($option);
                $resultGroup['values'] = $resultOptions;
            } else {
                $apiPath = 'api/property-group';
                $response = $shopwareClient->post($apiPath, [
                    'json' => [
                        "name" => $groupName,
                        "displayType" => "text",
                        "sortingType" => "alphanumeric",
                        "options" => array_map(fn ($g) => ['name' => $g['name']], $resultGroup['values'])
                    ]
                ]);
                $this->assertResponseStatus(204, $response, 'shopware', $apiPath);
                throw new RefetchShopwareIds();
            }
            $resultGroups[$groupName] = $resultGroup;
        }
        return $resultGroups;
    }

    private function findGroupId(array $groups, string $name): ?string
    {
        $id = null;
        foreach ($groups as $group) {
            if ($name === $group['attributes']['name']) {
                $id = $group['id'];
                break;
            }
        }
        return $id;
    }

    public function findOptionId(array $options, string $name, string $groupId): ?string
    {
        $id = null;
        foreach ($options as $option) {
            if ($name === $option['attributes']['name']
                && $option['attributes']['groupId'] == $groupId
            ) {
                $id = $option['id'];
                break;
            }
        }
        return $id;
    }

    private function findAttribute(array $attributes, int|string $attributeId): ?array
    {
        foreach ($attributes as $attribute) {
            if (is_string($attributeId) && $attribute['attribute_code'] === $attributeId) {
                return $attribute;
            } elseif ((int)$attribute['attribute_id'] === $attributeId) {
                return $attribute;
            }
        }
        return null;
    }

    private function findAttributeOption(array $attribute, int $optionId): ?array
    {
        foreach ($attribute['options'] as $option) {
            if ((int)$option['value'] === $optionId) {
                return $option;
            }
        }
        return null;
    }

    private function createShopwareClient(?string $accessToken = null): Client
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => '*/*',
        ];
        if ($accessToken) {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }
        return new Client([
            'base_uri' => $this->option('shopware-base-url'),
            'handler' => $this->handlerStack(),
            'headers' => $headers,
        ]);
    }

    private ?array $shopwareAuthData = null;
    private function getShopwareAuthData(): array
    {
        return $this->shopwareAuthData ??= $this->fetchShopwareAuthData();
    }

    private function fetchShopwareAuthData(): array
    {
        $shopwareAuthClient = $this->createShopwareClient();
        $apiPath = 'api/oauth/token';
        $response = $shopwareAuthClient->post($apiPath, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->option('shopware-api-key'),
                'client_secret' => $this->option('shopware-api-secret'),
            ]
        ]);
        $this->assertResponseStatus(200, $response, 'shopware', $apiPath);
        return json_decode((string)$response->getBody(), true);
    }

    private function createMagentoClient(): Client
    {
        return new Client([
            'base_uri' => $this->option('magento-base-url'),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->option('magento-access-token'),
            ],
        ]);
    }

    private function fetchMagentoAttributes(): array
    {
        $magentoClient = $this->createMagentoClient();
        $apiPath = 'rest/V1/products/attributes?searchCriteria=[]';
        $response = $magentoClient->get($apiPath);
        $this->assertResponseStatus(200, $response, 'magento', $apiPath);
        return json_decode((string)$response->getBody(), true)['items'];
    }

    private function fetchMagentoProducts(): array
    {
        $magentoClient = $this->createMagentoClient();
        $apiPath = 'rest/V1/products?searchCriteria=[]';
        $response = $magentoClient->get($apiPath);
        $this->assertResponseStatus(200, $response, 'magento', $apiPath);
        return json_decode((string)$response->getBody(), true)['items'];
    }

    private function assertResponseStatus(int $status, ResponseInterface $response, string $apiName, string $apiPath): void
    {
        if ($response->getStatusCode() != $status) {
            throw new \Exception(sprintf(
                'Unexpected response from %s API %s: %s [%s] ',
                $apiName,
                $apiPath,
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            ));
        }
    }
}
