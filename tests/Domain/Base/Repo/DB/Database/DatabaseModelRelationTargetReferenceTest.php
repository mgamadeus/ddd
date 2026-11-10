<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures\RelationTargetReferenceProbeDatabaseModel;
use PHPUnit\Framework\TestCase;

/**
 * A generated model writes its relation targets by short name. A module's model and an application's model of the
 * same path share that short name: an entity with a foreign key to the one and an inverse relation to the other
 * produced two imports of one name, a fatal error in the generated file.
 */
class DatabaseModelRelationTargetReferenceTest extends TestCase
{
    protected const string MESSAGE_MODEL = 'Module\Repo\DB\Conversations\Messages\DBConversationMessageModel';
    protected const string MODULE_CONVERSATION_MODEL = 'Module\Repo\DB\Conversations\DBConversationModel';
    protected const string APPLICATION_CONVERSATION_MODEL = 'Application\Repo\DB\Conversations\DBConversationModel';

    public function testATargetOfAnotherNamespaceIsImportedAndWrittenByItsShortName(): void
    {
        $databaseModel = RelationTargetReferenceProbeDatabaseModel::fromModelClassAndImports(static::MESSAGE_MODEL, []);

        $this->assertSame('DBConversationModel', $databaseModel->getReferenceNameOfRelationTargetModel(static::MODULE_CONVERSATION_MODEL));
        $this->assertSame(['use ' . static::MODULE_CONVERSATION_MODEL . ';'], $databaseModel->getImportDefinitions());
    }

    public function testATargetThatIsAlreadyImportedKeepsItsShortName(): void
    {
        $databaseModel = RelationTargetReferenceProbeDatabaseModel::fromModelClassAndImports(static::MESSAGE_MODEL, [static::MODULE_CONVERSATION_MODEL]);

        $this->assertSame('DBConversationModel', $databaseModel->getReferenceNameOfRelationTargetModel(static::MODULE_CONVERSATION_MODEL));
        $this->assertSame(['use ' . static::MODULE_CONVERSATION_MODEL . ';'], $databaseModel->getImportDefinitions());
    }

    public function testATargetWhoseShortNameIsImportedForAnotherClassIsWrittenFullyQualified(): void
    {
        $databaseModel = RelationTargetReferenceProbeDatabaseModel::fromModelClassAndImports(static::MESSAGE_MODEL, [static::MODULE_CONVERSATION_MODEL]);

        $this->assertSame(
            '\\' . static::APPLICATION_CONVERSATION_MODEL,
            $databaseModel->getReferenceNameOfRelationTargetModel(static::APPLICATION_CONVERSATION_MODEL)
        );
        $this->assertSame(['use ' . static::MODULE_CONVERSATION_MODEL . ';'], $databaseModel->getImportDefinitions(), 'no second import of the short name');
    }

    public function testATargetOfTheOwnNamespaceIsNotShadowedByAnImportOfItsShortName(): void
    {
        $databaseModel = RelationTargetReferenceProbeDatabaseModel::fromModelClassAndImports(
            'Application\Repo\DB\Conversations\DBConversationBatchModel',
            [static::MODULE_CONVERSATION_MODEL]
        );

        $this->assertSame(
            '\\' . static::APPLICATION_CONVERSATION_MODEL,
            $databaseModel->getReferenceNameOfRelationTargetModel(static::APPLICATION_CONVERSATION_MODEL)
        );
    }

    public function testATargetNamedLikeTheGeneratedModelIsWrittenFullyQualified(): void
    {
        $databaseModel = RelationTargetReferenceProbeDatabaseModel::fromModelClassAndImports(static::APPLICATION_CONVERSATION_MODEL, []);

        $this->assertSame(
            '\\' . static::MODULE_CONVERSATION_MODEL,
            $databaseModel->getReferenceNameOfRelationTargetModel(static::MODULE_CONVERSATION_MODEL)
        );
        $this->assertSame([], $databaseModel->getImportDefinitions());
        $this->assertSame('DBConversationModel', $databaseModel->getReferenceNameOfRelationTargetModel(static::APPLICATION_CONVERSATION_MODEL), 'the model itself');
    }
}
