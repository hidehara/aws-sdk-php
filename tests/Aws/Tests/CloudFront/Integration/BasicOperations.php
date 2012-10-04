<?php

namespace Aws\Tests\CloudFront\Integration;

use Aws\CloudFront\CloudFrontClient;
use Guzzle\Http\Client as HttpClient;

/**
 * @group integration
 */
class BasicOperationsTest extends \Aws\Tests\IntegrationTestCase
{
    /**
     * @var CloudFrontClient
     */
    public $client;
    protected static $originId;
    protected static $bucketName;
    protected static $distributionUrl;
    protected static $distributionId;

    public static function setUpBeforeClass()
    {
        $s3 = self::getServiceBuilder()->get('s3');
        self::$bucketName = crc32(gethostname()) . 'cftest';
        self::log('Creating bucket for testing distributions: ' . self::$bucketName);
        $s3->createBucket(array('bucket' => self::$bucketName))->execute();
        $s3->waitUntil('bucket_exists', self::$bucketName);
        self::log('Bucket created, adding test object...');
        $s3->putObject(array(
            'bucket'    => self::$bucketName,
            'key'       => 'foo.txt',
            'x-amz-acl' => 'public-read',
            'body'      => 'hello!'
        ))->execute();
        $s3->waitUntil('object_exists', self::$bucketName . '/foo.txt');
    }

    public static function tearDownAfterClass()
    {
        $s3 = self::getServiceBuilder()->get('s3');
        self::log('Deleting test object');
        $s3->deleteObject(array(
            'bucket' => self::$bucketName,
            'key'    => 'foo.txt'
        ))->execute();
        sleep(1);
        self::log('Deleting test bucket');
        $s3->deleteBucket(array('bucket' => self::$bucketName))->execute();

        $cf = self::getServiceBuilder()->get('cloudfront');
        if (self::$originId) {
            self::log('Deleting origin access identity');
            $cf->deleteCloudFrontOriginAccessIdentity(array('Id' => self::$originId));
        }
        if (self::$distributionId) {
            self::log('Deleting distribution');
            $cf->deleteDistribution(array('Id' => self::$distributionId));
        }
    }

    public function setUp()
    {
        $this->client = self::getServiceBuilder()->get('cloudfront');
    }

    public function testCreatesOrigins()
    {
        $command = $this->client->createCloudFrontOriginAccessIdentity(array(
            'CallerReference' => 'foo',
            'Comment'         => 'Hello!'
        ));
        $result = $command->getResult();
        $this->assertInstanceOf('Guzzle\Service\Resource\Model', $result);
        $result = $result->toArray();
        $this->assertArrayHasKey('Id', $result);
        self::$originId = $result['Id'];
        $this->assertArrayHasKey('S3CanonicalUserId', $result);
        $this->assertArrayHasKey('CloudFrontOriginAccessIdentityConfig', $result);
        $this->assertEquals(array(
            'CallerReference' => 'foo',
            'Comment'         => 'Hello!'
        ), $result['CloudFrontOriginAccessIdentityConfig']);
        $this->assertArrayHasKey('Location', $result);
        $this->assertArrayHasKey('ETag', $result);
        $this->assertEquals($result['Location'], (string) $command->getResponse()->getHeader('Location'));
        $this->assertEquals($result['ETag'], (string) $command->getResponse()->getHeader('ETag'));

        // Grant CF to read from the bucket
        $s3 = $this->getServiceBuilder()->get('s3');
        $s3->putObjectAcl(array(
            'bucket' => self::$bucketName,
            'key'    => 'foo.txt',
            'x-amz-grant-read' => 'id="' . $result['S3CanonicalUserId'] . '"'
        ))->execute();
    }

    /**
     * @depends testCreatesOrigins
     */
    public function testCreatesDistribution()
    {
        if (!self::$originId) {
            $this->fail('No originId was set');
        }

        self::log("Creating a distribution");

        $result = $this->client->createDistribution(array(
            'Aliases' => array('Quantity' => 0),
            'CacheBehaviors' => array('Quantity' => 0),
            'Comment' => 'Testing... 123',
            'Enabled' => true,
            'CallerReference' => 'BazBar-' . time(),
            'DefaultCacheBehavior' => array(
                'MinTTL' => 10,
                'ViewerProtocolPolicy' => 'allow-all',
                'TargetOriginId' => self::$originId,
                'TrustedSigners' => array(
                    'Enabled'  => true,
                    'Quantity' => 1,
                    'Items'    => array('self')
                ),
                'ForwardedValues' => array(
                    'QueryString' => false
                )
            ),
            'DefaultRootObject' => 'foo.txt',
            'Logging' => array(
                'Enabled' => false,
                'Bucket' => '',
                'Prefix' => ''
            ),
            'Origins' => array(
                'Quantity' => 1,
                'Items' => array(
                    array(
                        'Id' => self::$originId,
                        'DomainName' => self::$bucketName . '.s3.amazonaws.com',
                        'S3OriginConfig' => array(
                            'OriginAccessIdentity' => 'origin-access-identity/cloudfront/' . self::$originId
                        )
                    )
                )
            )
        ))->execute();

        $this->assertInstanceOf('Guzzle\Service\Resource\Model', $result);
        $result = $result->toArray();
        $this->assertArrayHasKey('Id', $result);
        self::$distributionId = $result['Id'];
        $this->assertArrayHasKey('Status', $result);
        $this->assertArrayHasKey('Location', $result);
        self::$distributionUrl = $result['DomainName'];
        $this->assertArrayHasKey('ETag', $result);
        $this->assertEquals(1, $result['DistributionConfig']['Origins']['Quantity']);
        $this->assertArrayHasKey(0, $result['DistributionConfig']['Origins']['Items']);
        $this->assertEquals(self::$bucketName . '.s3.amazonaws.com', $result['DistributionConfig']['Origins']['Items'][0]['DomainName']);
        $id = $result['Id'];

        $result = $this->client->listDistributions()->execute();
        $this->assertInstanceOf('Guzzle\Service\Resource\Model', $result);
        $result = $result->toArray();
        $this->assertGreaterThan(0, $result['Quantity']);
        $found = false;
        foreach ($result['Items'] as $item) {
            if ($item['Id'] == $id) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * @depends testCreatesDistribution
     */
    public function testCreatesSignedUrls()
    {
        self::log('Waiting until the distribution becomes active');
        $client = $this->getServiceBuilder()->get('cloudfront');
        $client->waitUntil('DistributionDeployed', self::$distributionId);
        $url = $client->getSignedUrl(array(
            'url'     => 'https://' . self::$distributionUrl . '/foo.txt',
            'expires' => time() + 10000
        ));
        $c = new HttpClient();
        $this->assertEquals('hello!', $c->get($url)->send()->getBody(true));
    }
}
