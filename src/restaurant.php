<?php
class FoodItem {
    private $id;
    private $itemName;
    private $price;

    public function __construct($id, $itemName, $price) {
        $this->id = $id;
        $this->itemName = $itemName;
        $this->price = $price;
    }

    public function getId() { return $this->id; }
    public function getItemName() { return $this->itemName; }
    public function getPrice() { return $this->price; }
}

class Restaurant {
    private $id;
    private $restaurantName;

    public function __construct($id, $restaurantName) {
        $this->id = $id;
        $this->restaurantName = $restaurantName;
    }

    public function getId() { return $this->id; }
    public function getRestaurantName() { return $this->restaurantName; }
}
?>