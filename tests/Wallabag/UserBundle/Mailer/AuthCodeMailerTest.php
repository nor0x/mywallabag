<?php

namespace Tests\Wallabag\UserBundle\Mailer;

use PHPUnit\Framework\TestCase;
use Wallabag\UserBundle\Entity\User;
use Wallabag\UserBundle\Mailer\AuthCodeMailer;

/**
 * @see https://www.pmg.com/blog/integration-testing-swift-mailer/
 */
final class CountableMemorySpool extends \Swift_MemorySpool implements \Countable
{
    public function count()
    {
        return \count($this->messages);
    }

    public function getMessages()
    {
        return $this->messages;
    }
}

class AuthCodeMailerTest extends TestCase
{
    protected $mailer;
    protected $spool;
    protected $twig;

    protected function setUp()
    {
        $this->spool = new CountableMemorySpool();
        $transport = new \Swift_Transport_SpoolTransport(
            new \Swift_Events_SimpleEventDispatcher(),
            $this->spool
        );
        $this->mailer = new \Swift_Mailer($transport);

        $twigTemplate = <<<'TWIG'
{% block subject %}subject{% endblock %}
{% block body_html %}html body {{ code }}{% endblock %}
{% block body_text %}text body {{ support_url }}{% endblock %}
TWIG;

        $this->twig = new \Twig_Environment(new \Twig_Loader_Array(['WallabagUserBundle:TwoFactor:email_auth_code.html.twig' => $twigTemplate]));
    }

    public function testSendEmail()
    {
        $user = new User();
        $user->setTwoFactorAuthentication(true);
        $user->setEmailAuthCode(666666);
        $user->setEmail('test@wallabag.io');
        $user->setName('Bob');

        $authCodeMailer = new AuthCodeMailer(
            $this->mailer,
            $this->twig,
            'nobody@test.io',
            'wallabag test',
            'http://0.0.0.0/support',
            'http://0.0.0.0/'
        );

        $authCodeMailer->sendAuthCode($user);

        $this->assertCount(1, $this->spool);

        $msg = $this->spool->getMessages()[0];
        $this->assertArrayHasKey('test@wallabag.io', $msg->getTo());
        $this->assertSame(['nobody@test.io' => 'wallabag test'], $msg->getFrom());
        $this->assertSame('subject', $msg->getSubject());
        $this->assertContains('text body http://0.0.0.0/support', $msg->toString());
        $this->assertContains('html body 666666', $msg->toString());
    }
}
