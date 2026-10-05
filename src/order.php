<?php
class Order {
    private $customerId;
    private $items = [];
    private $status = 'Pending';

    public function __construct($customerId) {
        $this->customerId = $customerId;
    }

    public function addItem(FoodItem $item, $quantity = 1) {
        $this->items[] = ['item' => $item, 'qty' => $quantity];
    }

    public function getTotalAmount() {
        $total = 0;
        foreach ($this->items as $orderItem) {
            $total += $orderItem['item']->getPrice() * $orderItem['qty'];
        }
        return $total;
    }

    public function saveToDatabase($pdo) {
        // 1. Insert into orders table
        $stmt = $pdo->prepare("INSERT INTO orders (customer_id, status, total_amount) VALUES (?, ?, ?)");
        $stmt->execute([$this->customerId, $this->status, $this->getTotalAmount()]);
        $orderId = $pdo->lastInsertId();

        // 2. Insert each item into order_items table
        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, food_item_id, quantity, price) VALUES (?, ?, ?, ?)");
        foreach ($this->items as $orderItem) {
            $food = $orderItem['item'];
            $itemStmt->execute([$orderId, $food->getId(), $orderItem['qty'], $food->getPrice()]);
        }

        return $orderId;
    }
}
?>