<?php

namespace App\DataFixtures;
use App\Entity\User;
use App\Entity\Category;
use App\Entity\Post;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $hasher;
    public function __construct(UserPasswordHasherInterface $hasher)
    {
        $this->hasher = $hasher;
    }
    public function load(ObjectManager $manager): void
    {
        // Beispiel-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword($this->hasher->hashPassword($user, 'password123'));
        $manager->persist($user);

        // Beispiel-Kategorie
        $category = new Category();
        $category->setName('Allgemein');
        $manager->persist($category);

        // Beispiel-Post (damit es was zum Abrufen gibt)
        $post = new Post();
        $post->setTitle('Erster Testpost');
        $post->setContent('Inhalt für Testzwecke.');
        $post->setAuthor($user);
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $manager->persist($post);

        $manager->flush();
    }
}
