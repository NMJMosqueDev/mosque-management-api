<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Create repeat-safe demo families and members for the mosque system'
)]
final class SeedDemoDataCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $io = new SymfonyStyle($input, $output);

        $areas = $this->connection->fetchAllAssociative(
            'SELECT id, area_code, area_name
             FROM areas
             WHERE is_active = TRUE
             ORDER BY area_code'
        );

        if (count($areas) !== 8) {
            $io->error(sprintf(
                'Expected 8 active areas, but found %d. No data was inserted.',
                count($areas)
            ));

            return Command::FAILURE;
        }

        $familiesAdded = 0;
        $membersAdded = 0;
        $familiesSkipped = 0;

        $this->connection->beginTransaction();

        try {
            foreach ($areas as $area) {
                for ($serial = 1; $serial <= 10; $serial++) {
                    $familyCode = sprintf(
                        '%s%03d',
                        $area['area_code'],
                        $serial
                    );

                    $existing = $this->connection->fetchOne(
                        'SELECT id FROM families WHERE family_code = ?',
                        [$familyCode]
                    );

                    if ($existing !== false) {
                        $familiesSkipped++;
                        continue;
                    }

                    $suffix = sprintf(
                        '%s Family %03d',
                        $area['area_code'],
                        $serial
                    );

                    $headId = $this->connection->fetchOne(
                        "INSERT INTO members
                            (name, gender, marital_status, member_status)
                         VALUES
                            (:name, 'male', 'married', 'active')
                         RETURNING id",
                        ['name' => 'DEMO ' . $suffix . ' Head']
                    );

                    $familyId = $this->connection->fetchOne(
                        "INSERT INTO families
                            (family_code, area_id, current_head_member_id,
                             address, family_status)
                         VALUES
                            (:code, :area_id, :head_id,
                             :address, 'active')
                         RETURNING id",
                        [
                            'code' => $familyCode,
                            'area_id' => $area['id'],
                            'head_id' => $headId,
                            'address' => 'DEMO DATA - NOT A REAL ADDRESS',
                        ]
                    );

                    $this->connection->insert('family_memberships', [
                        'family_id' => $familyId,
                        'member_id' => $headId,
                        'relationship_to_head' => 'other',
                    ]);

                    $membersAdded++;

                    $spouseId = $this->connection->fetchOne(
                        "INSERT INTO members
                            (name, gender, marital_status, member_status)
                         VALUES
                            (:name, 'female', 'married', 'active')
                         RETURNING id",
                        ['name' => 'DEMO ' . $suffix . ' Spouse']
                    );

                    $this->connection->insert('family_memberships', [
                        'family_id' => $familyId,
                        'member_id' => $spouseId,
                        'relationship_to_head' => 'wife',
                    ]);

                    $membersAdded++;

                    $childGender = $serial % 2 === 0 ? 'female' : 'male';
                    $childRelationship = $childGender === 'female'
                        ? 'daughter'
                        : 'son';
                    $childLabel = $childGender === 'female'
                        ? 'Daughter'
                        : 'Son';

                    $childId = $this->connection->fetchOne(
                        "INSERT INTO members
                            (name, gender, marital_status, member_status)
                         VALUES
                            (:name, :gender, 'single', 'active')
                         RETURNING id",
                        [
                            'name' => 'DEMO ' . $suffix . ' ' . $childLabel,
                            'gender' => $childGender,
                        ]
                    );

                    $this->connection->insert('family_memberships', [
                        'family_id' => $familyId,
                        'member_id' => $childId,
                        'relationship_to_head' => $childRelationship,
                    ]);

                    $membersAdded++;
                    $familiesAdded++;
                }
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            $io->error(
                'Demo seeding failed. The transaction was rolled back.'
            );

            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Demo data seeding completed.');

        $io->table(
            ['Result', 'Count'],
            [
                ['New demo families', $familiesAdded],
                ['New demo members', $membersAdded],
                ['Existing family codes skipped', $familiesSkipped],
                ['Active areas processed', count($areas)],
            ]
        );

        return Command::SUCCESS;
    }
}