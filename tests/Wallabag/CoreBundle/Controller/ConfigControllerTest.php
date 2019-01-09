<?php

namespace tests\Wallabag\CoreBundle\Controller;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Wallabag\AnnotationBundle\Entity\Annotation;
use Wallabag\CoreBundle\Entity\Config;
use Wallabag\CoreBundle\Entity\Entry;
use Wallabag\CoreBundle\Entity\Tag;
use Wallabag\UserBundle\Entity\User;

class ConfigControllerTest extends WallabagCoreTestCase
{
    public function testLogin()
    {
        $client = $this->getClient();

        $client->request('GET', '/new');

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('login', $client->getResponse()->headers->get('location'));
    }

    public function testIndex()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, $crawler->filter('button[id=config_save]'));
        $this->assertCount(1, $crawler->filter('button[id=change_passwd_save]'));
        $this->assertCount(1, $crawler->filter('button[id=update_user_save]'));
        $this->assertCount(1, $crawler->filter('button[id=rss_config_save]'));
    }

    public function testUpdate()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=config_save]')->form();

        $data = [
            'config[theme]' => 'baggy',
            'config[items_per_page]' => '30',
            'config[reading_speed]' => '0.5',
            'config[action_mark_as_read]' => '0',
            'config[language]' => 'en',
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertContains('flashes.config.notice.config_saved', $crawler->filter('body')->extract(['_text'])[0]);
    }

    public function testChangeReadingSpeed()
    {
        $this->logInAs('admin');
        $this->useTheme('baggy');
        $client = $this->getClient();

        $entry = new Entry($this->getLoggedInUser());
        $entry->setUrl('http://0.0.0.0/test-entry1')
            ->setReadingTime(22);
        $this->getEntityManager()->persist($entry);

        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();

        $crawler = $client->request('GET', '/unread/list');
        $form = $crawler->filter('button[id=submit-filter]')->form();
        $dataFilters = [
            'entry_filter[readingTime][right_number]' => 22,
            'entry_filter[readingTime][left_number]' => 22,
        ];
        $crawler = $client->submit($form, $dataFilters);
        $this->assertCount(1, $crawler->filter('div[class=entry]'));

        // Change reading speed
        $crawler = $client->request('GET', '/config');
        $form = $crawler->filter('button[id=config_save]')->form();
        $data = [
            'config[reading_speed]' => '2',
        ];
        $client->submit($form, $data);

        // Is the entry still available via filters?
        $crawler = $client->request('GET', '/unread/list');
        $form = $crawler->filter('button[id=submit-filter]')->form();
        $crawler = $client->submit($form, $dataFilters);
        $this->assertCount(0, $crawler->filter('div[class=entry]'));

        // Restore old configuration
        $crawler = $client->request('GET', '/config');
        $form = $crawler->filter('button[id=config_save]')->form();
        $data = [
            'config[reading_speed]' => '0.5',
        ];
        $client->submit($form, $data);
    }

    public function dataForUpdateFailed()
    {
        return [
            [[
                'config[theme]' => 'baggy',
                'config[items_per_page]' => '',
                'config[language]' => 'en',
            ]],
        ];
    }

    /**
     * @dataProvider dataForUpdateFailed
     */
    public function testUpdateFailed($data)
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=config_save]')->form();

        $crawler = $client->submit($form, $data);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $alert = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('This value should not be blank', $alert[0]);
    }

    public function dataForChangePasswordFailed()
    {
        return [
            [
                [
                    'change_passwd[old_password]' => 'material',
                    'change_passwd[new_password][first]' => '',
                    'change_passwd[new_password][second]' => '',
                ],
                'validator.password_wrong_value',
            ],
            [
                [
                    'change_passwd[old_password]' => 'mypassword',
                    'change_passwd[new_password][first]' => '',
                    'change_passwd[new_password][second]' => '',
                ],
                'This value should not be blank',
            ],
            [
                [
                    'change_passwd[old_password]' => 'mypassword',
                    'change_passwd[new_password][first]' => 'hop',
                    'change_passwd[new_password][second]' => '',
                ],
                'validator.password_must_match',
            ],
            [
                [
                    'change_passwd[old_password]' => 'mypassword',
                    'change_passwd[new_password][first]' => 'hop',
                    'change_passwd[new_password][second]' => 'hop',
                ],
                'validator.password_too_short',
            ],
        ];
    }

    /**
     * @dataProvider dataForChangePasswordFailed
     */
    public function testChangePasswordFailed($data, $expectedMessage)
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=change_passwd_save]')->form();

        $crawler = $client->submit($form, $data);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $alert = $crawler->filter('body')->extract(['_text']));
        $this->assertContains($expectedMessage, $alert[0]);
    }

    public function testChangePassword()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=change_passwd_save]')->form();

        $data = [
            'change_passwd[old_password]' => 'mypassword',
            'change_passwd[new_password][first]' => 'mypassword',
            'change_passwd[new_password][second]' => 'mypassword',
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertContains('flashes.config.notice.password_updated', $crawler->filter('body')->extract(['_text'])[0]);
    }

    public function dataForUserFailed()
    {
        return [
            [
                [
                    'update_user[name]' => '',
                    'update_user[email]' => '',
                ],
                'fos_user.email.blank',
            ],
            [
                [
                    'update_user[name]' => '',
                    'update_user[email]' => 'test',
                ],
                'fos_user.email.invalid',
            ],
        ];
    }

    /**
     * @dataProvider dataForUserFailed
     */
    public function testUserFailed($data, $expectedMessage)
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=update_user_save]')->form();

        $crawler = $client->submit($form, $data);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $alert = $crawler->filter('body')->extract(['_text']));
        $this->assertContains($expectedMessage, $alert[0]);
    }

    public function testUserUpdate()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=update_user_save]')->form();

        $data = [
            'update_user[name]' => 'new name',
            'update_user[email]' => 'admin@wallabag.io',
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $alert = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.config.notice.user_updated', $alert[0]);
    }

    public function testRssUpdateResetToken()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        // reset the token
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUsername('admin');

        if (!$user) {
            $this->markTestSkipped('No user found in db.');
        }

        $config = $user->getConfig();
        $config->setRssToken(null);
        $em->persist($config);
        $em->flush();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('config.form_rss.no_token', $body[0]);

        $client->request('GET', '/generate-token');
        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertNotContains('config.form_rss.no_token', $body[0]);
    }

    public function testGenerateTokenAjax()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $client->request(
            'GET',
            '/generate-token',
            [],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest']
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $content = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $content);
    }

    public function testRssUpdate()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=rss_config_save]')->form();

        $data = [
            'rss_config[rss_limit]' => 12,
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertContains('flashes.config.notice.rss_updated', $crawler->filter('body')->extract(['_text'])[0]);
    }

    public function dataForRssFailed()
    {
        return [
            [
                [
                    'rss_config[rss_limit]' => 0,
                ],
                'This value should be 1 or more.',
            ],
            [
                [
                    'rss_config[rss_limit]' => 1000000000000,
                ],
                'validator.rss_limit_too_high',
            ],
        ];
    }

    /**
     * @dataProvider dataForRssFailed
     */
    public function testRssFailed($data, $expectedMessage)
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=rss_config_save]')->form();

        $crawler = $client->submit($form, $data);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $alert = $crawler->filter('body')->extract(['_text']));
        $this->assertContains($expectedMessage, $alert[0]);
    }

    public function testTaggingRuleCreation()
    {
        $this->logInAs('admin');
        $this->useTheme('baggy');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=tagging_rule_save]')->form();

        $data = [
            'tagging_rule[rule]' => 'readingTime <= 3',
            'tagging_rule[tags]' => 'short reading',
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertContains('flashes.config.notice.tagging_rules_updated', $crawler->filter('body')->extract(['_text'])[0]);

        $editLink = $crawler->filter('.mode_edit')->last()->link();

        $crawler = $client->click($editLink);
        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('?tagging-rule=', $client->getResponse()->headers->get('location'));

        $crawler = $client->followRedirect();

        $form = $crawler->filter('button[id=tagging_rule_save]')->form();

        $data = [
            'tagging_rule[rule]' => 'readingTime <= 30',
            'tagging_rule[tags]' => 'short reading',
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertContains('flashes.config.notice.tagging_rules_updated', $crawler->filter('body')->extract(['_text'])[0]);

        $this->assertContains('readingTime <= 30', $crawler->filter('body')->extract(['_text'])[0]);

        $deleteLink = $crawler->filter('.delete')->last()->link();

        $crawler = $client->click($deleteLink);
        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();
        $this->assertContains('flashes.config.notice.tagging_rules_deleted', $crawler->filter('body')->extract(['_text'])[0]);
    }

    public function dataForTaggingRuleFailed()
    {
        return [
            [
                [
                    'tagging_rule[rule]' => 'unknownVar <= 3',
                    'tagging_rule[tags]' => 'cool tag',
                ],
                [
                    'The variable',
                    'does not exist.',
                ],
            ],
            [
                [
                    'tagging_rule[rule]' => 'length(domainName) <= 42',
                    'tagging_rule[tags]' => 'cool tag',
                ],
                [
                    'The operator',
                    'does not exist.',
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataForTaggingRuleFailed
     */
    public function testTaggingRuleCreationFail($data, $messages)
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=tagging_rule_save]')->form();

        $crawler = $client->submit($form, $data);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));

        foreach ($messages as $message) {
            $this->assertContains($message, $body[0]);
        }
    }

    public function testTaggingRuleTooLong()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=tagging_rule_save]')->form();

        $crawler = $client->submit($form, [
            'tagging_rule[rule]' => str_repeat('title', 60),
            'tagging_rule[tags]' => 'cool tag',
        ]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));

        $this->assertContains('255 characters', $body[0]);
    }

    public function testDeletingTaggingRuleFromAnOtherUser()
    {
        $this->logInAs('bob');
        $client = $this->getClient();

        $rule = $client->getContainer()->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:TaggingRule')
            ->findAll()[0];

        $crawler = $client->request('GET', '/tagging-rule/edit/' . $rule->getId());

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('You can not access this tagging rule', $body[0]);
    }

    public function testEditingTaggingRuleFromAnOtherUser()
    {
        $this->logInAs('bob');
        $client = $this->getClient();

        $rule = $client->getContainer()->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:TaggingRule')
            ->findAll()[0];

        $crawler = $client->request('GET', '/tagging-rule/edit/' . $rule->getId());

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('You can not access this tagging rule', $body[0]);
    }

    public function testDemoMode()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $config = $client->getContainer()->get('craue_config');
        $config->set('demo_mode_enabled', 1);
        $config->set('demo_mode_username', 'admin');

        $crawler = $client->request('GET', '/config');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('button[id=change_passwd_save]')->form();

        $data = [
            'change_passwd[old_password]' => 'mypassword',
            'change_passwd[new_password][first]' => 'mypassword',
            'change_passwd[new_password][second]' => 'mypassword',
        ];

        $client->submit($form, $data);

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('flashes.config.notice.password_not_updated_demo', $client->getContainer()->get('session')->getFlashBag()->get('notice')[0]);

        $config->set('demo_mode_enabled', 0);
        $config->set('demo_mode_username', 'wallabag');
    }

    public function testDeleteUserButtonVisibility()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/config');

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('config.form_user.delete.button', $body[0]);

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUsername('empty');
        $user->setEnabled(false);
        $em->persist($user);

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUsername('bob');
        $user->setEnabled(false);
        $em->persist($user);

        $em->flush();

        $crawler = $client->request('GET', '/config');

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertNotContains('config.form_user.delete.button', $body[0]);

        $client->request('GET', '/account/delete');
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUsername('empty');
        $user->setEnabled(true);
        $em->persist($user);

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUsername('bob');
        $user->setEnabled(true);
        $em->persist($user);

        $em->flush();
    }

    public function testDeleteAccount()
    {
        $client = $this->getClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $user = new User();
        $user->setName('Wallace');
        $user->setEmail('wallace@wallabag.org');
        $user->setUsername('wallace');
        $user->setPlainPassword('wallace');
        $user->setEnabled(true);
        $user->addRole('ROLE_SUPER_ADMIN');

        $em->persist($user);

        $config = new Config($user);

        $config->setTheme('material');
        $config->setItemsPerPage(30);
        $config->setReadingSpeed(1);
        $config->setLanguage('en');
        $config->setPocketConsumerKey('xxxxx');

        $em->persist($config);
        $em->flush();

        $this->logInAs('wallace');
        $loggedInUserId = $this->getLoggedInUserId();

        // create entry to check after user deletion
        // that this entry is also deleted
        $crawler = $client->request('GET', '/new');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('form[name=entry]')->form();
        $data = [
            'entry[url]' => $url = 'https://github.com/wallabag/wallabag',
        ];

        $client->submit($form, $data);
        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $crawler = $client->request('GET', '/config');

        $deleteLink = $crawler->filter('.delete-account')->last()->link();

        $client->click($deleteLink);
        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->createQueryBuilder('u')
            ->where('u.username = :username')->setParameter('username', 'wallace')
            ->getQuery()
            ->getOneOrNullResult()
        ;

        $this->assertNull($user);

        $entries = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUser($loggedInUserId);

        $this->assertEmpty($entries);
    }

    public function testReset()
    {
        $this->logInAs('empty');
        $client = $this->getClient();

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $user = static::$kernel->getContainer()->get('security.token_storage')->getToken()->getUser();

        $tag = new Tag();
        $tag->setLabel('super');
        $em->persist($tag);

        $entry = new Entry($user);
        $entry->setUrl('https://www.lemonde.fr/europe/article/2016/10/01/pour-le-psoe-chaque-election-s-est-transformee-en-une-agonie_5006476_3214.html');
        $entry->setContent('Youhou');
        $entry->setTitle('Youhou');
        $entry->addTag($tag);
        $em->persist($entry);

        $entry2 = new Entry($user);
        $entry2->setUrl('http://www.lemonde.de/europe/article/2016/10/01/pour-le-psoe-chaque-election-s-est-transformee-en-une-agonie_5006476_3214.html');
        $entry2->setContent('Youhou');
        $entry2->setTitle('Youhou');
        $entry2->addTag($tag);
        $em->persist($entry2);

        $annotation = new Annotation($user);
        $annotation->setText('annotated');
        $annotation->setQuote('annotated');
        $annotation->setRanges([]);
        $annotation->setEntry($entry);
        $em->persist($annotation);

        $em->flush();

        // reset annotations
        $crawler = $client->request('GET', '/config#set3');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->click($crawler->selectLink('config.reset.annotations')->link());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('flashes.config.notice.annotations_reset', $client->getContainer()->get('session')->getFlashBag()->get('notice')[0]);

        $annotationsReset = $em
            ->getRepository('WallabagAnnotationBundle:Annotation')
            ->findAnnotationsByPageId($entry->getId(), $user->getId());

        $this->assertEmpty($annotationsReset, 'Annotations were reset');

        // reset tags
        $crawler = $client->request('GET', '/config#set3');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->click($crawler->selectLink('config.reset.tags')->link());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('flashes.config.notice.tags_reset', $client->getContainer()->get('session')->getFlashBag()->get('notice')[0]);

        $tagReset = $em
            ->getRepository('WallabagCoreBundle:Tag')
            ->countAllTags($user->getId());

        $this->assertSame(0, $tagReset, 'Tags were reset');

        // reset entries
        $crawler = $client->request('GET', '/config#set3');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->click($crawler->selectLink('config.reset.entries')->link());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('flashes.config.notice.entries_reset', $client->getContainer()->get('session')->getFlashBag()->get('notice')[0]);

        $entryReset = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->countAllEntriesByUser($user->getId());

        $this->assertSame(0, $entryReset, 'Entries were reset');
    }

    public function testResetArchivedEntries()
    {
        $this->logInAs('empty');
        $client = $this->getClient();

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $user = static::$kernel->getContainer()->get('security.token_storage')->getToken()->getUser();

        $tag = new Tag();
        $tag->setLabel('super');
        $em->persist($tag);

        $entry = new Entry($user);
        $entry->setUrl('https://www.lemonde.fr/europe/article/2016/10/01/pour-le-psoe-chaque-election-s-est-transformee-en-une-agonie_5006476_3214.html');
        $entry->setContent('Youhou');
        $entry->setTitle('Youhou');
        $entry->addTag($tag);
        $em->persist($entry);

        $annotation = new Annotation($user);
        $annotation->setText('annotated');
        $annotation->setQuote('annotated');
        $annotation->setRanges([]);
        $annotation->setEntry($entry);
        $em->persist($annotation);

        $tagArchived = new Tag();
        $tagArchived->setLabel('super');
        $em->persist($tagArchived);

        $entryArchived = new Entry($user);
        $entryArchived->setUrl('https://www.lemonde.fr/europe/article/2016/10/01/pour-le-psoe-chaque-election-s-est-transformee-en-une-agonie_5006476_3214.html');
        $entryArchived->setContent('Youhou');
        $entryArchived->setTitle('Youhou');
        $entryArchived->addTag($tagArchived);
        $entryArchived->setArchived(true);
        $em->persist($entryArchived);

        $annotationArchived = new Annotation($user);
        $annotationArchived->setText('annotated');
        $annotationArchived->setQuote('annotated');
        $annotationArchived->setRanges([]);
        $annotationArchived->setEntry($entryArchived);
        $em->persist($annotationArchived);

        $em->flush();

        $crawler = $client->request('GET', '/config#set3');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->click($crawler->selectLink('config.reset.archived')->link());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('flashes.config.notice.archived_reset', $client->getContainer()->get('session')->getFlashBag()->get('notice')[0]);

        $entryReset = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->countAllEntriesByUser($user->getId());

        $this->assertSame(1, $entryReset, 'Entries were reset');

        $tagReset = $em
            ->getRepository('WallabagCoreBundle:Tag')
            ->countAllTags($user->getId());

        $this->assertSame(1, $tagReset, 'Tags were reset');

        $annotationsReset = $em
            ->getRepository('WallabagAnnotationBundle:Annotation')
            ->findAnnotationsByPageId($annotationArchived->getId(), $user->getId());

        $this->assertEmpty($annotationsReset, 'Annotations were reset');
    }

    public function testResetEntriesCascade()
    {
        $this->logInAs('empty');
        $client = $this->getClient();

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $user = static::$kernel->getContainer()->get('security.token_storage')->getToken()->getUser();

        $tag = new Tag();
        $tag->setLabel('super');
        $em->persist($tag);

        $entry = new Entry($user);
        $entry->setUrl('https://www.lemonde.fr/europe/article/2016/10/01/pour-le-psoe-chaque-election-s-est-transformee-en-une-agonie_5006476_3214.html');
        $entry->setContent('Youhou');
        $entry->setTitle('Youhou');
        $entry->addTag($tag);
        $em->persist($entry);

        $annotation = new Annotation($user);
        $annotation->setText('annotated');
        $annotation->setQuote('annotated');
        $annotation->setRanges([]);
        $annotation->setEntry($entry);
        $em->persist($annotation);

        $em->flush();

        $crawler = $client->request('GET', '/config#set3');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->click($crawler->selectLink('config.reset.entries')->link());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertContains('flashes.config.notice.entries_reset', $client->getContainer()->get('session')->getFlashBag()->get('notice')[0]);

        $entryReset = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->countAllEntriesByUser($user->getId());

        $this->assertSame(0, $entryReset, 'Entries were reset');

        $tagReset = $em
            ->getRepository('WallabagCoreBundle:Tag')
            ->countAllTags($user->getId());

        $this->assertSame(0, $tagReset, 'Tags were reset');

        $annotationsReset = $em
            ->getRepository('WallabagAnnotationBundle:Annotation')
            ->findAnnotationsByPageId($entry->getId(), $user->getId());

        $this->assertEmpty($annotationsReset, 'Annotations were reset');
    }

    public function testSwitchViewMode()
    {
        $this->logInAs('admin');
        $this->useTheme('baggy');
        $client = $this->getClient();

        $client->request('GET', '/unread/list');

        $this->assertNotContains('listmode', $client->getResponse()->getContent());

        $client->request('GET', '/config/view-mode');
        $crawler = $client->followRedirect();

        $client->request('GET', '/unread/list');

        $this->assertContains('listmode', $client->getResponse()->getContent());

        $client->request('GET', '/config/view-mode');
    }
}
