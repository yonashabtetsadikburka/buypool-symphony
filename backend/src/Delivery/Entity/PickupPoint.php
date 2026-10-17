<?php

namespace App\Delivery\Entity;

use App\Delivery\Repository\PickupPointRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PickupPointRepository::class)]
#[ORM\Table(name: 'pickup_points')]
class PickupPoint
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 100)]
    private string $city;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $openingHours = null;

    public function __construct(string $name, string $address, string $city)
    {
        $this->id = Uuid::v7();
        $this->name = $this->requireNonEmpty($name, 'Pickup point name');
        $this->address = $this->requireNonEmpty($address, 'Pickup point address');
        $this->city = $this->requireNonEmpty($city, 'Pickup point city');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getOpeningHours(): ?string
    {
        return $this->openingHours;
    }

    public function setOpeningHours(?string $openingHours): self
    {
        $this->openingHours = $openingHours;

        return $this;
    }

    private function requireNonEmpty(string $value, string $label): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException($label.' cannot be empty.');
        }

        return $value;
    }
}
