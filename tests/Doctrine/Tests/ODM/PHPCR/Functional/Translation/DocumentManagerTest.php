<?php

namespace Doctrine\Tests\ODM\PHPCR\Functional\Translation;

use Doctrine\Tests\Models\Translation\Article,
    Doctrine\Tests\Models\Translation\Comment,
    Doctrine\Tests\Models\Translation\InvalidMapping,
    Doctrine\Tests\Models\Translation\DerivedArticle,
    Doctrine\Tests\Models\CMS\CmsArticle,
    Doctrine\Tests\ODM\PHPCR\PHPCRFunctionalTestCase;
use Doctrine\ODM\PHPCR\Mapping\ClassMetadata;

use Doctrine\ODM\PHPCR\Translation\TranslationStrategy\AttributeTranslationStrategy,
    Doctrine\ODM\PHPCR\Translation\LocaleChooser\LocaleChooser;


class DocumentManagerTest extends PHPCRFunctionalTestCase
{
    protected $testNodeName = '__my_test_node__';

    /**
     * @var \Doctrine\ODM\PHPCR\DocumentManager
     */
    protected $dm;

    /**
     * @var \PHPCR\SessionInterface
     */
    protected $session;

    /**
     * @var \PHPCR\NodeInterface
     */
    protected $node;

    /**
     * @var \Doctrine\ODM\PHPCR\Mapping\ClassMetadata
     */
    protected $metadata;

    /**
     * @var Article
     */
    protected $doc;

    /**
     * @var string
     */
    protected $class = 'Doctrine\Tests\Models\Translation\Article';

    /**
     * @var string
     */
    protected $childrenClass = 'Doctrine\Tests\Models\Translation\Comment';

    protected $localePrefs = array(
        'en' => array('de', 'fr'),
        'fr' => array('de', 'en'),
        'de' => array('en'),
        'it' => array('fr', 'de', 'en'),
    );

    public function setUp()
    {
        $this->dm = $this->createDocumentManager();
        $this->dm->setLocaleChooserStrategy(new LocaleChooser($this->localePrefs, 'en'));
        $this->node = $this->resetFunctionalNode($this->dm);
        $this->dm->clear();

        $this->session = $this->dm->getPhpcrSession();
        $this->metadata = $this->dm->getClassMetadata($this->class);

        $this->doc = new Article();
        $this->doc->id = '/functional/' . $this->testNodeName;
        $this->doc->author = 'John Doe';
        $this->doc->topic = 'Some interesting subject';
        $this->doc->setText('Lorem ipsum...');
        $this->doc->setSettings(array());
    }

    protected function getTestNode()
    {
        return $this->session->getNode('/functional/'.$this->testNodeName);
    }

    protected function assertDocumentStored()
    {
        $node = $this->getTestNode();
        $this->assertNotNull($node);

        $this->assertFalse($node->hasProperty('topic'));
        $this->assertTrue($node->hasProperty(AttributeTranslationStrategyTest::propertyNameForLocale('en', 'topic')));
        $this->assertEquals('Some interesting subject', $node->getPropertyValue(AttributeTranslationStrategyTest::propertyNameForLocale('en', 'topic')));
        $this->assertTrue($node->hasProperty(AttributeTranslationStrategyTest::propertyNameForLocale('fr', 'topic')));
        $this->assertEquals('Un sujet intéressant', $node->getPropertyValue(AttributeTranslationStrategyTest::propertyNameForLocale('fr', 'topic')));

        $this->assertEquals('fr', $this->doc->locale);
    }

    public function testPersistNew()
    {
        $this->dm->persist($this->doc);
        $this->dm->flush();

        // Assert the node exists
        $node = $this->getTestNode();
        $this->assertNotNull($node);

        $this->assertFalse($node->hasProperty('topic'));

        $this->assertTrue($node->hasProperty(AttributeTranslationStrategyTest::propertyNameForLocale('en', 'topic')));
        $this->assertEquals('Some interesting subject', $node->getPropertyValue(AttributeTranslationStrategyTest::propertyNameForLocale('en', 'topic')));

        $this->assertTrue($node->hasProperty('author'));
        $this->assertEquals('John Doe', $node->getPropertyValue('author'));

        $this->assertEquals('en', $this->doc->locale);
    }

    public function testBindTranslation()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->flush();

        $this->doc->topic = 'Un sujet intéressant';
        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();

        $this->assertDocumentStored();
    }

    public function testRemoveTranslation()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->flush();

        $this->assertEquals(array('en'), $this->dm->getLocalesFor($this->doc));

        $this->doc->topic = 'Un sujet intéressant';
        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();

        $this->dm->clear();
        $this->doc = $this->dm->findTranslation(null, $this->doc->id, 'fr');

        $this->assertEquals(array('en', 'fr'), $this->dm->getLocalesFor($this->doc));

        $this->dm->removeTranslation($this->doc, 'en');
        $this->assertEquals(array('fr'), $this->dm->getLocalesFor($this->doc));
        $this->dm->clear();

        $this->doc = $this->dm->findTranslation(null, $this->doc->id, 'fr');
        $this->assertEquals(array('en', 'fr'), $this->dm->getLocalesFor($this->doc));
        $this->dm->removeTranslation($this->doc, 'en');

        $this->dm->flush();
        $this->assertEquals(array('fr'), $this->dm->getLocalesFor($this->doc));

        $this->dm->clear();
        $this->doc = $this->dm->find(null, $this->doc->id);

        $this->assertEquals(array('fr'), $this->dm->getLocalesFor($this->doc));

        try {
            $this->dm->removeTranslation($this->doc, 'fr');
            $this->fail('Last translation should not be removable');
        } catch (\RuntimeException $e) {

        }
    }

    /**
     * find translation in non-default language and then save it back has to keep language
     */
    public function testFindTranslationAndUpdate()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->flush();

        $this->doc->topic = 'Un sujet intéressant';
        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();
        $this->dm->clear();

        $this->doc = $this->dm->findTranslation(null, '/functional/' . $this->testNodeName, 'fr');
        $this->doc->topic = 'Un sujet intéressant';
        $this->dm->flush();

        $this->assertDocumentStored();
    }

    /**
     * changing the locale and flushing should pick up changes automatically
     */
    public function testUpdateLocalAndFlush()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->flush();

        $this->doc->topic = 'Un sujet intéressant';
        $this->doc->locale = 'fr';
        $this->dm->flush();

        $this->assertDocumentStored();
    }

    public function testFlush()
    {
        $this->dm->persist($this->doc);

        $this->doc->topic = 'Un sujet intéressant';

        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();

        $this->doc->topic = 'Un autre sujet';
        $this->dm->flush();

        $node = $this->getTestNode();
        $this->assertNotNull($node);

        $this->assertTrue($node->hasProperty(AttributeTranslationStrategyTest::propertyNameForLocale('fr', 'topic')));
        $this->assertEquals('Un autre sujet', $node->getPropertyValue(AttributeTranslationStrategyTest::propertyNameForLocale('fr', 'topic')));

        $this->assertEquals('fr', $this->doc->locale);
    }

    public function testFind()
    {
        $this->dm->persist($this->doc);
        $this->dm->flush();

        $this->doc = $this->dm->find($this->class, '/functional/' . $this->testNodeName);

        $this->assertNotNull($this->doc);
        $this->assertEquals('en', $this->doc->locale);

        $node = $this->getTestNode();
        $this->assertNotNull($node);
        $this->assertTrue($node->hasProperty(AttributeTranslationStrategyTest::propertyNameForLocale('en', 'topic')));
        $this->assertEquals('Some interesting subject', $node->getPropertyValue(AttributeTranslationStrategyTest::propertyNameForLocale('en', 'topic')));
    }

    public function testFindTranslation()
    {
        $this->doc->topic = 'Un autre sujet';
        $this->doc->locale = 'fr';
        $this->dm->persist($this->doc);
        $this->dm->flush();

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'fr');

        $this->assertNotNull($this->doc);
        $this->assertEquals('fr', $this->doc->locale);
        $this->assertEquals('Un autre sujet', $this->doc->topic);
    }

    /**
     * Test that children are retrieved in the parent locale
     */
    public function testFindTranslationWithChildren()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');

        $comment = new Comment();
        $comment->name = 'new-comment';
        $comment->parent = $this->doc;
        $this->dm->persist($comment);

        $comment->setText('This is a great article');
        $this->dm->bindTranslation($comment, 'en');
        $comment->setText('Très bon article');
        $this->dm->bindTranslation($comment, 'fr');
        $this->dm->flush();

        $this->dm->clear();

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'fr');
        $this->assertEquals('en', $this->doc->locale);
        $children = $this->doc->getChildren();
        foreach ($children as $comment) {
            $this->assertEquals('fr', $comment->locale);
            $this->assertEquals('Très bon article', $comment->getText());
        }

        $this->doc->topic = 'Un autre sujet';
        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'fr');
        $this->assertEquals('fr', $this->doc->locale);
        $children = $this->doc->getChildren();
        $this->assertCount(1, $children);
        foreach ($children as $comment) {
            $this->assertEquals('fr', $comment->locale);
            $this->assertEquals('Très bon article', $comment->getText());
        }
        $children = $this->dm->getChildren($this->doc);
        $this->assertCount(1, $children);
        foreach ($children as $comment) {
            $this->assertEquals('fr', $comment->locale);
            $this->assertEquals('Très bon article', $comment->getText());
        }

        $this->metadata->mappings['children']['cascade'] = ClassMetadata::CASCADE_TRANSLATION;

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'en');
        $this->assertEquals('en', $this->doc->locale);
        $children = $this->doc->getChildren();
        $this->assertCount(1, $children);
        foreach ($children as $comment) {
            $this->assertEquals('en', $comment->locale);
            $this->assertEquals('This is a great article', $comment->getText());
        }
        $children = $this->dm->getChildren($this->doc);
        $this->assertCount(1, $children);
        foreach ($children as $comment) {
            $this->assertEquals('en', $comment->locale);
            $this->assertEquals('This is a great article', $comment->getText());
        }
    }

    /**
     * Test that children are retrieved in the parent locale
     */
    public function testFindTranslationWithUntranslatedChildren()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');

        $this->doc->topic = 'Un autre sujet';
        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();

        $testNode = $this->node->getNode($this->testNodeName);
        $testNode->addNode('new-comment');
        $this->session->save();

        $this->dm->clear();

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'fr');
        $this->assertEquals('fr', $this->doc->locale);
        $children = $this->doc->getChildren();
        $this->assertCount(1, $children);
        foreach ($children as $comment) {
            $this->assertInstanceOf('Doctrine\ODM\PHPCR\Document\Generic', $comment);
            $this->assertNull($this->dm->getUnitOfWork()->getCurrentLocale($comment));
        }

        $this->dm->clear();

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'en');
        $children = $this->dm->getChildren($this->doc);
        $this->assertCount(1, $children);
        foreach ($children as $comment) {
            $this->assertInstanceOf('Doctrine\ODM\PHPCR\Document\Generic', $comment);
            $this->assertNull($this->dm->getUnitOfWork()->getCurrentLocale($comment));
        }
    }

    public function testFindByUUID()
    {
        $this->doc->topic = 'Un autre sujet';
        $this->doc->locale = 'fr';
        $this->dm->persist($this->doc);
        $this->dm->flush();

        $node = $this->session->getNode('/functional/'.$this->testNodeName);
        $node->addMixin('mix:referenceable');
        $this->session->save();

        $this->document = $this->dm->findTranslation($this->class, $node->getIdentifier(), 'fr');
        $this->assertInstanceOf($this->class, $this->document);
    }

    /**
     * Italian translation does not exist so as defined in $this->localePrefs we
     * will get french as it has higher priority than english
     */
    public function testFindTranslationWithLanguageFallback()
    {
        $this->dm->persist($this->doc);
        $this->doc->topic = 'Un autre sujet';
        $this->doc->locale = 'fr';
        $this->dm->flush();

        $this->doc = $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'it');

        $this->assertNotNull($this->doc);
        $this->assertEquals('fr', $this->doc->locale);
        $this->assertEquals('Un autre sujet', $this->doc->topic);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testFindTranslationWithInvalidLanguageFallback()
    {
        $this->dm->persist($this->doc);
        $this->dm->flush();

        $this->dm->findTranslation($this->class, '/functional/' . $this->testNodeName, 'es');
    }

    public function testGetLocaleFor()
    {
        // Only 1 language is persisted
        $this->dm->persist($this->doc);
        $this->dm->flush();

        $locales = $this->dm->getLocalesFor($this->doc);
        $this->assertEquals(array('en'), $locales);

        // A second language is persisted
        $this->dm->bindTranslation($this->doc, 'fr');
        // Check that french is now also returned even without having flushed
        $locales = $this->dm->getLocalesFor($this->doc);
        $this->assertCount(2, $locales);
        $this->assertTrue(in_array('en', $locales));
        $this->assertTrue(in_array('fr', $locales));

        $this->dm->flush();

        $locales = $this->dm->getLocalesFor($this->doc);
        $this->assertCount(2, $locales);
        $this->assertTrue(in_array('en', $locales));
        $this->assertTrue(in_array('fr', $locales));

        // A third language is bound but not yet flushed
        $this->dm->bindTranslation($this->doc, 'de');

        $locales = $this->dm->getLocalesFor($this->doc);
        $this->assertCount(3, $locales);
        $this->assertTrue(in_array('en', $locales));
        $this->assertTrue(in_array('fr', $locales));
        $this->assertTrue(in_array('de', $locales));
    }

    public function testRemove()
    {
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->bindTranslation($this->doc, 'fr');
        $this->dm->flush();

        $this->dm->remove($this->doc);
        $this->dm->flush();
        $this->dm->clear();

        $this->doc = $this->dm->find($this->class, '/functional/' . $this->testNodeName);
        $this->assertNull($this->doc, 'Document must be null after deletion');

        $this->doc = new Article();
        $this->doc->id = '/functional/' . $this->testNodeName;
        $this->doc->author = 'John Doe';
        $this->doc->topic = 'Some interesting subject';
        $this->doc->setText('Lorem ipsum...');
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->flush();

        $locales = $this->dm->getLocalesFor($this->doc);
        $this->assertEquals(array('en'), $locales, 'Removing a document must remove all translations');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testInvalidTranslationStrategy()
    {
        $this->doc = new InvalidMapping();
        $this->doc->id = '/functional/' . $this->testNodeName;
        $this->doc->topic = 'foo';
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->dm->flush();
    }

    /**
     * bindTranslation with a document that is not persisted should fail
     *
     * @expectedException \InvalidArgumentException
     */
    public function testBindTranslationWithoutPersist()
    {
        $this->doc = new CmsArticle();
        $this->doc->id = '/functional/' . $this->testNodeName;
        $this->dm->bindTranslation($this->doc, 'en');
    }

    /**
     * bindTranslation with a document that is not translatable should fail
     *
     * @expectedException \Doctrine\ODM\PHPCR\PHPCRException
     */
    public function testBindTranslationNonTranslatable()
    {
        $this->doc = new CmsArticle();
        $this->doc->id = '/functional/' . $this->testNodeName;
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
    }

    /**
     * bindTranslation with a document inheriting from a translatable document
     * should not fail
     */
    public function testBindTranslationInherited() {
        $this->doc = new DerivedArticle();
        $this->doc->id = '/functional/' . $this->testNodeName;
        $this->dm->persist($this->doc);
        $this->dm->bindTranslation($this->doc, 'en');
        $this->assertEquals('en', $this->doc->locale);
    }
}
