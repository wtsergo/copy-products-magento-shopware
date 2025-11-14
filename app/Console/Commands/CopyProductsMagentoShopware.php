<?php

namespace Wtsergo\CopyProductsMagentoShopware\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

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
            $magentoClient = new Client([
                'base_uri' => $this->option('magento-base-url'),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$this->option('magento-access-token'),
                ],
            ]);
            $response = $magentoClient->get('rest/V1/products?searchCriteria=[]');
            if ($response->getStatusCode() != 200) {
                throw new \Exception(sprintf(
                    'Unexpected response from magento API: %s [%s] ',
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                ));
            }
            $products = json_decode((string)$response->getBody(), true);

            $history = [];
            $handlerStack = HandlerStack::create();
            $handlerStack->push(Middleware::history($history));

            $shopwareAuthClient = new Client([
                'handler' => $handlerStack,
                'base_uri' => $this->option('shopware-base-url'),
            ]);
            $shopwareAuthApiPath = 'api/oauth/token';
            $shopwareAuthResponse = $shopwareAuthClient->post($shopwareAuthApiPath, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->option('shopware-api-key'),
                    'client_secret' => $this->option('shopware-api-secret'),
                ]
            ]);

            if ($response->getStatusCode() != 200) {
                throw new \Exception(sprintf(
                    'Unexpected response from shopware API %s : %s [%s] ',
                    $shopwareAuthApiPath,
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                ));
            }

            $shopwareAuthData = json_decode((string)$shopwareAuthResponse->getBody(), true);

            $shopwareClient = new Client([
                'handler' => $handlerStack,
                'base_uri' => $this->option('shopware-base-url'),
                'headers' => [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$shopwareAuthData['access_token'],
                ],
            ]);

            foreach ($products['items'] as $product) {
                $createData = [
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
                ];
                $shopwareClient->post('api/product', [
                    'body' => json_encode($createData),
                    //'json' => >$createData
                ]);
            }
        } catch (\Throwable $throwable) {
            foreach ($history as $transaction) {
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
}
