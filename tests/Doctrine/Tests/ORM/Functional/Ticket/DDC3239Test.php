<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-3239
 * @group non-cacheable
 */
class DDC3239Test extends OrmFunctionalTestCase
{

    public function setUp()
    {
        parent::setUp();
        self::registerTypes();
        $this->initSchema();
        $this->initData();
    }

    public static function registerTypes()
    {
        if (Type::hasType('ddc3239_currency_code')) {
            static::fail(
                'Type ddc3239_currency_code exists for testing DDC-3239 only, ' .
                'but it has already been registered for some reason'
            );
        }

        Type::addType('ddc3239_currency_code', __NAMESPACE__ . '\DDC3239CurrencyCode');
    }

    public function initSchema()
    {
        $this->_schemaTool->createSchema(array(
            $this->_em->getClassMetadata(DDC3239Currency::CLASSNAME),
            $this->_em->getClassMetadata(DDC3239Transaction::CLASSNAME),
            $this->_em->getClassMetadata(DDC3239CurrencyExchangePoint::CLASSNAME),
        ));
    }

    public function initData()
    {
        $this->loadCurrencies();
        $this->loadTransactions();
    }

    private function loadCurrencies()
    {
        foreach (array_keys(DDC3239CurrencyCode::$map) as $currencyCode) {
            $currency = new DDC3239Currency($currencyCode);
            $this->_em->persist($currency);
        }

        $this->_em->flush();
        $this->_em->clear();
    }


    private function loadTransactions()
    {
        $em = $this->_em;

        $transactions = array(
            array(50, 'BYR'),
        );

        foreach ($transactions as $i => $transactionInfo) {
            /** @var DDC3239Currency $currency */
            $currency = $em->find(DDC3239Currency::CLASSNAME, $transactionInfo[1]);
            $transaction = new DDC3239Transaction(
                $i + 1, // Id of the entity, counting from 1
                $transactionInfo[0],
                $currency
            );
            $em->persist($transaction);
        }

        $currencyExcahngePoints = array(
            array(array('BYR', 'EUR'), array('EUR', 'USD'))
        );

        foreach ($currencyExcahngePoints as $i => $currencyExchangePointInfo) {

            $currencyLoader = function ($code) use ($em) {
                return $em->find(DDC3239Currency::CLASSNAME, $code);
            };

            $exchanger = new DDC3239CurrencyExchangePoint(
                $i + 1,
                array_map($currencyLoader, $currencyExchangePointInfo[0]),
                array_map($currencyLoader, $currencyExchangePointInfo[1])
            );

            $em->persist($exchanger);
        }

        $em->flush();
        $em->close();
    }

    public function testIssue()
    {
        $fetchedCurrency = $this->_em->find(DDC3239Currency::CLASSNAME, 'BYR');
        $this->assertCount(1, $fetchedCurrency->transactions);

        $transaction = $this->_em->find(DDC3239Transaction::CLASSNAME, 1);
        $this->assertInstanceOf(DDC3239Currency::CLASSNAME, $transaction->currency);
        $this->assertEquals('BYR', $transaction->currency->code);

        /** @var DDC3239CurrencyExchangePoint $exchanger */
        $exchanger = $this->_em->find(DDC3239CurrencyExchangePoint::CLASSNAME, 1);
        $this->assertCount(2, $exchanger->inputCurrencies);
        foreach ($exchanger->inputCurrencies as $inputCurrency) {
            /** @var DDC3239Currency $inputCurrency */
            $this->assertInstanceOf(DDC3239Currency::CLASSNAME, $inputCurrency);
            $this->assertArrayHasKey($inputCurrency->code, DDC3239CurrencyCode::$map);
        }
    }
}

/**
 * @Table(name="ddc3239_currencies")
 * @Entity
 */
class DDC3239Currency
{
    const CLASSNAME = __CLASS__;

    /**
     * @Id
     * @Column(type="ddc3239_currency_code")
     */
    public $code;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @OneToMany(targetEntity="DDC3239Transaction", mappedBy="currency")
     */
    public $transactions;

    /**
     * @param string $code
     */
    public function __construct($code)
    {
        $this->code = $code;
    }
}

/**
 * @Table(name="ddc3239_transactions")
 * @Entity
 */
class DDC3239Transaction
{
    const CLASSNAME = __CLASS__;

    /**
     * @var int
     *
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var int
     *
     * @Column(type="integer")
     */
    public $amount;

    /**
     * @var DDC3239Currency
     *
     * @ManyToOne(targetEntity="DDC3239Currency", inversedBy="transactions")
     * @JoinColumn(name="currency_id", referencedColumnName="code", nullable=false)
     */
    public $currency;

    public function __construct($id, $amount, DDC3239Currency $currency)
    {
        $this->id = $id;
        $this->amount = $amount;
        $this->currency = $currency;
    }
}

/**
 * @Table(name="ddc3239_currency_exchange_points")
 * @Entity
 */
class DDC3239CurrencyExchangePoint
{
    const CLASSNAME = __CLASS__;

    /**
     * @var int
     *
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @ManyToMany(targetEntity="DDC3239Currency")
     * @JoinTable(name="ddc3239_exchanges_input_currencies",
     *     joinColumns={@JoinColumn(name="exchanger_id", referencedColumnName="id")},
     *     inverseJoinColumns={@JoinColumn(name="input_currency_id", referencedColumnName="code")}
     * )
     */
    public $inputCurrencies;

    /**
     * @ManyToMany(targetEntity="DDC3239Currency")
     * @JoinTable(name="ddc3239_exchanges_output_currencies",
     *     joinColumns={@JoinColumn(name="exchanger_id", referencedColumnName="id")},
     *     inverseJoinColumns={@JoinColumn(name="output_currency_id", referencedColumnName="code")}
     * )
     */
    public $outputCurrencies;


    public function __construct($id, $inputCurrencies, $outputCurrencies)
    {
        $this->id = $id;
        $this->inputCurrencies = $inputCurrencies;
        $this->outputCurrencies = $outputCurrencies;
    }
}

class DDC3239CurrencyCode extends Type
{
    public static $map = array(
        'BYR' => 974,
        'EUR' => 978,
        'USD' => 840,
    );

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getSmallIntTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return self::$map[$value];
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return array_search($value, self::$map);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'ddc3239_currency_code';
    }
}
