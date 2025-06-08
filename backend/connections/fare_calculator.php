<?php

class FareCalculator {
    // Base fare for first 4km
    private $baseFare = 12.00; 
    
    // Rate per additional kilometer
    private $ratePerKm = 1.80;
    
    // Discount percentages for special passengers
    private $discounts = [
        'student' => 20, 
        'senior' => 20,  
        'pwd' => 20      
    ];
    
    /**
     * Calculate fare based on distance and passenger type
     * 
     * @param float $distance
     * @param string $passengerType 
     * @return float 
     */
    public function calculateFare($distance, $passengerType = 'regular') {
        // Calculate the basic fare
        $fare = $this->baseFare; 
        
        // Add fare for additional distance beyond 4km
        if ($distance > 4) {
            $additionalDistance = $distance - 4;
            $fare += $additionalDistance * $this->ratePerKm;
        }
        
        // Round fare to nearest 0.25 peso 
        $fare = $this->roundToQuarter($fare);
        
        // Apply discount if applicable
        if (isset($this->discounts[strtolower($passengerType)])) {
            $discountPercentage = $this->discounts[strtolower($passengerType)];
            $discountAmount = $fare * ($discountPercentage / 100);
            $fare -= $discountAmount;
            
            // Round discounted fare again
            $fare = $this->roundToQuarter($fare);
        }
        
        return $fare;
    }
    
    /**
     * Round amount to nearest 0.25 peso
     * 
     * @param float $amount Amount to round
     * @return float Rounded amount
     */
    private function roundToQuarter($amount) {
        return round($amount * 4) / 4;
    }
    
    /**
     * Get estimated fare range for a route
     * 
     * @param float $distance Distance in kilometers
     * @return array Fare range (min and max fares)
     */
    public function getFareRange($distance) {
        // Regular fare
        $regularFare = $this->calculateFare($distance, 'regular');
        
        // Discounted fare (all special types have same discount in this implementation)
        $discountedFare = $this->calculateFare($distance, 'student');
        
        return [
            'min' => $discountedFare,
            'max' => $regularFare,
            'regular' => $regularFare,
            'discounted' => $discountedFare
        ];
    }
    
    /**
     * Get fare table for multiple distances
     * 
     * @param int $maxDistance Maximum distance to calculate
     * @param int $interval Distance interval
     * @return array Fare table
     */
    public function getFareTable($maxDistance, $increment, $startDistance = 4) {
        $fareTable = [];
        
        for ($distance = $startDistance; $distance <= $maxDistance; $distance += $increment) {
            $fareRange = $this->getFareRange($distance);
            $fareTable[] = [
                'distance' => $distance,
                'regular' => $fareRange['regular'],
                'student' => $fareRange['discounted']
            ];
        }
        
        return $fareTable;
    }
}
?>