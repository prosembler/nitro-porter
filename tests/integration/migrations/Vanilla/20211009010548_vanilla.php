<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Vanilla extends AbstractMigration
{
    /**
     * Write your reversible migrations using this method.
     *
     * @see https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     */
    public function change(): void
    {
        // User
        $table = $this->table('GDN_User', ['id' => 'UserID']);
        $table->addColumn('Name', 'string', ['limit' => 50])
            ->addColumn('Password', 'varbinary', ['limit' => 100])
            ->addColumn('HashMethod', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('Photo', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('Title', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('Email', 'string', ['limit' => 200])
            ->addColumn('DateInserted', 'datetime', ['null' => true])
            ->addColumn('DateUpdated', 'datetime', ['null' => true])
            ->addColumn('InviteUserID', 'integer', ['null' => true])
            ->addColumn('Admin', 'smallinteger', ['default' => 0])
            ->addColumn('Confirmed', 'smallinteger', ['default' => 0])
            ->addColumn('Verified', 'smallinteger', ['default' => 0])
            ->addColumn('Banned', 'smallinteger', ['default' => 0])
            ->addColumn('Deleted', 'smallinteger', ['default' => 0])
            ->create();
    }
}
