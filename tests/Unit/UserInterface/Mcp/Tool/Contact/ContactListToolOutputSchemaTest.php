<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Contact;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\ContactBundle\Entity\AccountRepositoryInterface;
use Sulu\Bundle\ContactBundle\Entity\ContactRepositoryInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Contact\ContactListTool;

#[CoversClass(ContactListTool::class)]
final class ContactListToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testContactResultMatchesOutputSchema(): void
    {
        $contactRepository = $this->prophesize(ContactRepositoryInterface::class);
        $contactRepository->findGetAll(Argument::cetera())
            ->willReturn([['id' => 1, 'firstName' => 'John', 'lastName' => 'Doe']]);

        $accountRepository = $this->prophesize(AccountRepositoryInterface::class);

        $tool = new ContactListTool($contactRepository->reveal(), $accountRepository->reveal());

        $result = $tool->listContacts('contact');

        $this->assertResultMatchesOutputSchema(ContactListTool::class, 'listContacts', $result);
    }

    public function testAccountResultMatchesOutputSchema(): void
    {
        $contactRepository = $this->prophesize(ContactRepositoryInterface::class);

        $accountRepository = $this->prophesize(AccountRepositoryInterface::class);
        $accountRepository->findAllSelect(Argument::any())->willReturn([['id' => 1, 'name' => 'Acme Corp']]);

        $tool = new ContactListTool($contactRepository->reveal(), $accountRepository->reveal());

        $result = $tool->listContacts('account');

        $this->assertResultMatchesOutputSchema(ContactListTool::class, 'listContacts', $result);
    }

    public function testErrorResultMatchesOutputSchema(): void
    {
        $contactRepository = $this->prophesize(ContactRepositoryInterface::class);
        $contactRepository->findGetAll(Argument::cetera())->willThrow(new \RuntimeException('Bundle not installed'));

        $accountRepository = $this->prophesize(AccountRepositoryInterface::class);

        $tool = new ContactListTool($contactRepository->reveal(), $accountRepository->reveal());

        $result = $tool->listContacts();

        $this->assertResultMatchesOutputSchema(ContactListTool::class, 'listContacts', $result);
    }
}
