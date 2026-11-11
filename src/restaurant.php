<?php
class FoodItem {
    private $id;
    private $itemName;
    private $price;
    private $category; // Added category property

    public function __construct($id, $itemName, $price, $category = 'General') {
        $this->id = $id;
        $this->itemName = $itemName;
        $this->price = $price;
        $this->category = $category;
    }

    public function getId() { return $this->id; }
    public function getItemName()  { return $this->itemName; }
    public function getPrice()  { return $this->price; }
    public function getCategory() { return $this->category; } // Added getter
}
// ... rest of your Restaurant and User classes
?>
