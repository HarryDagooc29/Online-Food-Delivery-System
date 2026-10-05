<?php
class User {
    protected $id;
    protected $name;
    protected $email;

    public function __construct($id, $name, $email) {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
    }

    public function getProfileDetails() {
        return "{$this->name} ({$this->email})";
    }
}

class Customer extends User {
    private $deliveryAddress;

    public function __construct($id, $name, $email, $deliveryAddress) {
        parent::__construct($id, $name, $email);
        $this->deliveryAddress = $deliveryAddress;
    }

    public function getDeliveryAddress() {
        return $this->deliveryAddress;
    }
}
?>