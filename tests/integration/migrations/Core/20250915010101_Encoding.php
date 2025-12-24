<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Encoding extends AbstractMigration
{
    /**
     * Write your reversible migrations using this method.
     *
     * @see https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     */
    public function change(): void
    {
        // A) utf8mb4_unicode_ci.
        $table = $this->table('EncodingA', ['collation' => 'utf8mb4_unicode_ci']);
        $table->addColumn('Name', 'string', ['limit' => 50])
            ->addColumn('DiscussionID', 'integer')
            ->addColumn('InsertUserID', 'integer')
            ->addColumn('Body', 'text')
            ->addColumn('DateInserted', 'datetime')
            ->create();

        // B) latin1_swedish_ci
        $table = $this->table('EncodingB', ['collation' => 'latin1_swedish_ci']);
        $table->addColumn('Name', 'string', ['limit' => 50])
            ->addColumn('DiscussionID', 'integer')
            ->addColumn('InsertUserID', 'integer')
            ->addColumn('Body', 'text')
            ->addColumn('DateInserted', 'datetime')
            ->create();

        // C) utf8mb3_general_ci
        $table = $this->table('EncodingC', ['collation' => 'utf8mb3_general_ci']);
        $table->addColumn('Name', 'string', ['limit' => 50])
            ->addColumn('DiscussionID', 'integer')
            ->addColumn('InsertUserID', 'integer')
            ->addColumn('Body', 'text')
            ->addColumn('DateInserted', 'datetime')
            ->create();

        // D) cp1250_general_ci
        $table = $this->table('EncodingD', ['collation' => 'cp1250_general_ci']);
        $table->addColumn('Name', 'string', ['limit' => 50])
            ->addColumn('DiscussionID', 'integer')
            ->addColumn('InsertUserID', 'integer')
            ->addColumn('Body', 'text')
            ->addColumn('DateInserted', 'datetime')
            ->create();
    }
}
